<?php

declare(strict_types=1);

namespace Frosh\Rector\Rule\ClassConstructor;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PHPStan\Type\ArrayType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use Rector\Core\Contract\Rector\ConfigurableRectorInterface;
use Rector\Core\Rector\AbstractRector;
use Rector\PHPStanStaticTypeMapper\Enum\TypeKind;
use Rector\StaticTypeMapper\StaticTypeMapper;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

class MakeClassConstructorArgumentRequiredRector extends AbstractRector implements ConfigurableRectorInterface
{
    public function __construct(private readonly StaticTypeMapper $typeMapper)
    {
    }

    /**
     * @var MakeClassConstructorArgumentRequired[]
     */
    protected array $configuration;

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('NAME', [
            new ConfiguredCodeSample(
                <<<'PHP'
                    class Foo {
                        public function __construct(array $foo = [])
                        {
                        }
                    }
                    PHP
                ,
                <<<'PHP'
                    class Foo {
                        public function __construct(array $foo)
                        {
                        }
                    }
                    PHP,
                [new MakeClassConstructorArgumentRequired('Foo', 0, new ArrayType(new StringType(), new StringType()))],
            ),
        ]);
    }

    public function getNodeTypes(): array
    {
        return [
            Node\Stmt\Class_::class,
            Node\Expr\New_::class,
        ];
    }

    /**
     * @param Node\Stmt\ClassMethod|Node\Expr\New_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node instanceof Class_) {
            $changes = false;
            foreach ($node->stmts as $classMethod) {
                if (($classMethod instanceof Node\Stmt\ClassMethod) && $this->rebuildClassMethod($node, $classMethod)) {
                    $changes = true;
                }
            }

            if ($changes) {
                return $node;
            }

            return null;
        }

        return $this->rebuildNew($node);
    }

    /**
     * @param MakeClassConstructorArgumentRequired[] $configuration
     */
    public function configure(array $configuration): void
    {
        $this->configuration = $configuration;
    }

    private function rebuildClassMethod(Class_ $class, Node\Stmt\ClassMethod $node): bool
    {
        if (!$this->isName($node, '__construct')) {
            return false;
        }

        $hasModified = false;

        foreach ($this->configuration as $config) {
            if (!$this->isObjectType($class, $config->getClassObject())) {
                continue;
            }

            if (!isset($node->params[$config->getPosition()])) {
                continue;
            }

            $node->params[$config->getPosition()]->default = null;

            $hasModified = true;
        }

        if ($hasModified) {
            return true;
        }

        return false;
    }

    private function rebuildNew(Node\Expr\New_ $node): ?Node
    {
        $hasModified = false;

        foreach ($this->configuration as $config) {
            if (!$this->isObjectType($node->class, $config->getClassObject())) {
                continue;
            }

            if (isset($node->args[$config->getPosition()])) {
                continue;
            }

            if ($config->getDefault()) {
                /** @var Node\Name $arg */
                $arg = $this->typeMapper->mapPHPStanTypeToPhpParserNode($config->getDefault(), TypeKind::PARAM);

                if ($config->getDefault() instanceof NullType) {
                    if ($arg instanceof Node\Identifier) {
                        $arg = new Node\Name($arg->name);
                    }

                    if ($arg === null) {
                        $arg = new Node\Name('null');
                    }

                    $arg = new Node\Expr\ConstFetch($arg);
                }

                $node->args[$config->getPosition()] = new Node\Arg($arg);
            }

            $hasModified = true;
        }

        if ($hasModified) {
            return $node;
        }

        return null;
    }
}
