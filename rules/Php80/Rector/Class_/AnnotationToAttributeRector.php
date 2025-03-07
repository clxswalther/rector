<?php

declare(strict_types=1);

namespace Rector\Php80\Rector\Class_;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\Property;
use Rector\BetterPhpDocParser\PhpDoc\DoctrineAnnotationTagValueNode;
use Rector\BetterPhpDocParser\PhpDocInfo\PhpDocInfo;
use Rector\BetterPhpDocParser\PhpDocManipulator\PhpDocTagRemover;
use Rector\Core\Contract\Rector\ConfigurableRectorInterface;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\Php80\ValueObject\AnnotationToAttribute;
use Rector\PhpAttribute\Printer\PhpAttributeGroupFactory;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use Webmozart\Assert\Assert;

/**
 * @see https://wiki.php.net/rfc/attributes_v2
 * @see https://wiki.php.net/rfc/shorter_attribute_syntax
 * @see https://wiki.php.net/rfc/shorter_attribute_syntax_change - FINAL #[...] syntax !
 *
 * @see \Rector\Tests\Php80\Rector\Class_\AnnotationToAttributeRector\AnnotationToAttributeRectorTest
 */
final class AnnotationToAttributeRector extends AbstractRector implements ConfigurableRectorInterface
{
    /**
     * @var string
     */
    public const ANNOTATION_TO_ATTRIBUTE = 'annotation_to_attribute';

    /**
     * @var AnnotationToAttribute[]
     */
    private $annotationsToAttributes = [];

    /**
     * @var PhpAttributeGroupFactory
     */
    private $phpAttributeGroupFactory;

    /**
     * @var PhpDocTagRemover
     */
    private $phpDocTagRemover;

    public function __construct(
        PhpAttributeGroupFactory $phpAttributeGroupFactory,
        PhpDocTagRemover $phpDocTagRemover
    ) {
        $this->phpAttributeGroupFactory = $phpAttributeGroupFactory;
        $this->phpDocTagRemover = $phpDocTagRemover;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition('Change annotation to attribute', [
            new ConfiguredCodeSample(
                <<<'CODE_SAMPLE'
use Symfony\Component\Routing\Annotation\Route;

class SymfonyRoute
{
    /**
     * @Route("/path", name="action")
     */
    public function action()
    {
    }
}
CODE_SAMPLE
,
                <<<'CODE_SAMPLE'
use Symfony\Component\Routing\Annotation\Route;

class SymfonyRoute
{
    #[Route(path: '/path', name: 'action')]
    public function action()
    {
    }
}
CODE_SAMPLE
                , [
                    self::ANNOTATION_TO_ATTRIBUTE => [
                        new AnnotationToAttribute(
                            'Symfony\Component\Routing\Annotation\Route',
                            'Symfony\Component\Routing\Annotation\Route'
                        ),
                    ],
                ]
            ),
        ]);
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [
            Class_::class,
            Property::class,
            ClassMethod::class,
            Function_::class,
            Closure::class,
            ArrowFunction::class,
        ];
    }

    /**
     * @param Class_|Property|ClassMethod|Function_|Closure|ArrowFunction $node
     */
    public function refactor(Node $node): ?Node
    {
        if (! $this->isAtLeastPhpVersion(PhpVersionFeature::ATTRIBUTES)) {
            return null;
        }

        $phpDocInfo = $this->phpDocInfoFactory->createFromNode($node);
        if (! $phpDocInfo instanceof PhpDocInfo) {
            return null;
        }

        $hasNewAttrGroups = false;

        foreach ($this->annotationsToAttributes as $annotationToAttribute) {
            $tag = $annotationToAttribute->getTag();

            // 1. simple doc tags
            if ($phpDocInfo->hasByName($tag)) {
                // 1. remove php-doc tag
                $this->phpDocTagRemover->removeByName($phpDocInfo, $tag);

                // 2. add attributes
                $node->attrGroups[] = $this->phpAttributeGroupFactory->createFromSimpleTag($annotationToAttribute);

                $hasNewAttrGroups = true;
            }

            $doctrineAnnotationTagValueNode = $phpDocInfo->getByAnnotationClass($tag);
            if (! $doctrineAnnotationTagValueNode instanceof DoctrineAnnotationTagValueNode) {
                continue;
            }

            // 1. remove php-doc tag
            $this->phpDocTagRemover->removeTagValueFromNode($phpDocInfo, $doctrineAnnotationTagValueNode);

            // 2. add attributes
            $node->attrGroups[] = $this->phpAttributeGroupFactory->create(
                $doctrineAnnotationTagValueNode,
                $annotationToAttribute
            );

            $hasNewAttrGroups = true;
        }

        if ($hasNewAttrGroups) {
            return $node;
        }

        return null;
    }

    /**
     * @param array<string, AnnotationToAttribute[]> $configuration
     */
    public function configure(array $configuration): void
    {
        $annotationsToAttributes = $configuration[self::ANNOTATION_TO_ATTRIBUTE] ?? [];
        Assert::allIsInstanceOf($annotationsToAttributes, AnnotationToAttribute::class);

        $this->annotationsToAttributes = $annotationsToAttributes;
    }
}
