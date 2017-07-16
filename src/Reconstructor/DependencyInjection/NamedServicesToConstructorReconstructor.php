<?php declare(strict_types=1);

namespace Rector\Reconstructor\DependencyInjection;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use Rector\Builder\ConstructorMethodBuilder;
use Rector\Builder\Naming\NameResolver;
use Rector\Builder\PropertyBuilder;
use Rector\Contract\Dispatcher\ReconstructorInterface;
use Rector\Tests\Reconstructor\DependencyInjection\NamedServicesToConstructorReconstructor\Source\LocalKernel;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Kernel;

final class NamedServicesToConstructorReconstructor implements ReconstructorInterface
{
    /**
     * @var ConstructorMethodBuilder
     */
    private $constructorMethodBuilder;

    /**
     * @var PropertyBuilder
     */
    private $propertyBuilder;

    /**
     * @var NameResolver
     */
    private $nameResolver;

    public function __construct(
        ConstructorMethodBuilder $constructorMethodBuilder,
        PropertyBuilder $propertyBuilder,
        NameResolver $nameResolver
    ) {
        $this->constructorMethodBuilder = $constructorMethodBuilder;
        $this->propertyBuilder = $propertyBuilder;
        $this->nameResolver = $nameResolver;
    }

    public function isCandidate(Node $node): bool
    {
        // @todo: limit to only 2 cases:
        // - SomeClass extends Controller
        // - SomeClass implements ContainerAwareInterface

        // OR? Maybe listen on MethodCall... $this-> +get('...')
        return $node instanceof Class_;
    }

    /**
     * @param Class_ $classNode
     */
    public function reconstruct(Node $classNode): void
    {
        foreach ($classNode->stmts as $insideClassNode) {
            // 1. Detect method
            if (! $insideClassNode instanceof ClassMethod) {
                continue;
            }

            $methodNode = $insideClassNode;

            foreach ($methodNode->stmts as $insideMethodNode) {
                // A. Find $this->get('...')->someCall()
                if ($insideMethodNode instanceof MethodCall && $insideMethodNode->var instanceof MethodCall) {
                    $this->processOnServiceMethodCall($classNode, $insideMethodNode);

                // B. Find $var = $this->get('...');
                } elseif ($insideMethodNode instanceof Assign) {
                    $this->processAssignment($classNode, $insideMethodNode);
                }
            }
        }
    }

    private function processOnServiceMethodCall(Class_ $classNode, MethodCall $methodCallNode): void
    {
        if (! $this->isContainerGetCall($methodCallNode)) {
            return;
        }

        $refactoredMethodCall = $this->processMethodCallNode($classNode, $methodCallNode->var);
        if ($refactoredMethodCall) {
            $methodCallNode->var = $refactoredMethodCall;
        }
    }

    private function processAssignment(Class_ $classNode, Assign $assignNode): void
    {
        if (!$this->isContainerGetCall($assignNode)) {
            return;
        }

        $refactoredMethodCall = $this->processMethodCallNode($classNode, $assignNode->expr);
        if ($refactoredMethodCall) {
            $assignNode->expr = $refactoredMethodCall;
        }
    }

    /**
     * @todo extract to helper service, LocalKernelProvider::get...()
     */
    private function getContainerFromKernelClass(): ContainerInterface
    {
        /** @var Kernel $kernel */
        $kernel = new LocalKernel('dev', true);
        $kernel->boot();

        // @todo: initialize without creating cache or log directory
        // @todo: call only loadBundles() and initializeContainer() methods

        return $kernel->getContainer();
    }

    /**
     * Accept only "$this->get('string')" statements.
     */
    private function isContainerGetCall(Node $node): bool
    {
        if ($node instanceof Assign && ($node->expr instanceof MethodCall || $node->var instanceof MethodCall)) {
            $methodCall = $node->expr;
        } elseif ($node instanceof MethodCall && $node->var instanceof MethodCall) {
            $methodCall = $node->var;
        } else {
            return false;
        }

        if ($methodCall->var->name !== 'this') {
            return false;
        }

        if ($methodCall->name !== 'get') {
            return false;
        }

        if (! $methodCall->args[0]->value instanceof String_) {
            return false;
        }

        return true;
    }

    /**
     * @param MethodCall|Expr $methodCallNode
     */
    private function resolveServiceTypeFromMethodCall($methodCallNode): ?string
    {
        /** @var String_ $argument */
        $argument = $methodCallNode->args[0]->value;
        $serviceName = $argument->value;

        $container = $this->getContainerFromKernelClass();
        if (! $container->has($serviceName)) {
            // service name could not be found
            return null;
        }

        $service = $container->get($serviceName);

        return get_class($service);
    }

    private function processMethodCallNode(Class_ $classNode, MethodCall $methodCall): ?PropertyFetch
    {
        // 1. Get service type
        $serviceType = $this->resolveServiceTypeFromMethodCall($methodCall);
        if ($serviceType === null) {
            return null;
        }

        // 2. Property name
        $propertyName = $this->nameResolver->resolvePropertyNameFromType($serviceType);

        // 4. Add property assignment to constructor
        $this->constructorMethodBuilder->addPropertyAssignToClass($classNode, $serviceType, $propertyName);

        // 5. Add property to class
        $this->propertyBuilder->addPropertyToClass($classNode, $serviceType, $propertyName);

        return new PropertyFetch(
            new Variable('this', [
                'name' => $propertyName
            ]), $propertyName
        );

    }
}
