<?php

declare(strict_types=1);

namespace Rector\PHPStanStaticTypeMapper\TypeMapper;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\NullableType;
use PhpParser\Node\UnionType as PhpParserUnionType;
use PhpParser\NodeAbstract;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\Type\IterableType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeWithClassName;
use PHPStan\Type\UnionType;
use PHPStan\Type\VoidType;
use Rector\BetterPhpDocParser\ValueObject\Type\BracketsAwareUnionTypeNode;
use Rector\CodeQuality\Tests\Rector\If_\ExplicitBoolCompareRector\Fixture\Nullable;
use Rector\Core\Exception\ShouldNotHappenException;
use Rector\Core\Php\PhpVersionProvider;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\ValueObject\PhpVersionFeature;
use Rector\PHPStanStaticTypeMapper\Contract\TypeMapperInterface;
use Rector\PHPStanStaticTypeMapper\DoctrineTypeAnalyzer;
use Rector\PHPStanStaticTypeMapper\PHPStanStaticTypeMapper;
use Rector\PHPStanStaticTypeMapper\TypeAnalyzer\BoolUnionTypeAnalyzer;
use Rector\PHPStanStaticTypeMapper\TypeAnalyzer\UnionTypeAnalyzer;
use Rector\PHPStanStaticTypeMapper\TypeAnalyzer\UnionTypeCommonTypeNarrower;
use Rector\PHPStanStaticTypeMapper\ValueObject\UnionTypeAnalysis;

final class UnionTypeMapper implements TypeMapperInterface
{
    /**
     * @var PHPStanStaticTypeMapper
     */
    private $phpStanStaticTypeMapper;

    /**
     * @var PhpVersionProvider
     */
    private $phpVersionProvider;

    /**
     * @var UnionTypeAnalyzer
     */
    private $unionTypeAnalyzer;

    /**
     * @var DoctrineTypeAnalyzer
     */
    private $doctrineTypeAnalyzer;

    /**
     * @var BoolUnionTypeAnalyzer
     */
    private $boolUnionTypeAnalyzer;

    /**
     * @var UnionTypeCommonTypeNarrower
     */
    private $unionTypeCommonTypeNarrower;

    public function __construct(
        DoctrineTypeAnalyzer $doctrineTypeAnalyzer,
        PhpVersionProvider $phpVersionProvider,
        UnionTypeAnalyzer $unionTypeAnalyzer,
        BoolUnionTypeAnalyzer $boolUnionTypeAnalyzer,
        UnionTypeCommonTypeNarrower $unionTypeCommonTypeNarrower
    ) {
        $this->phpVersionProvider = $phpVersionProvider;
        $this->unionTypeAnalyzer = $unionTypeAnalyzer;
        $this->doctrineTypeAnalyzer = $doctrineTypeAnalyzer;
        $this->boolUnionTypeAnalyzer = $boolUnionTypeAnalyzer;
        $this->unionTypeCommonTypeNarrower = $unionTypeCommonTypeNarrower;
    }

    /**
     * @required
     */
    public function autowireUnionTypeMapper(PHPStanStaticTypeMapper $phpStanStaticTypeMapper): void
    {
        $this->phpStanStaticTypeMapper = $phpStanStaticTypeMapper;
    }

    /**
     * @return class-string<Type>
     */
    public function getNodeClass(): string
    {
        return UnionType::class;
    }

    /**
     * @param UnionType $type
     */
    public function mapToPHPStanPhpDocTypeNode(Type $type): TypeNode
    {
        $unionTypesNodes = [];
        $skipIterable = $this->shouldSkipIterable($type);

        foreach ($type->getTypes() as $unionedType) {
            if ($unionedType instanceof IterableType && $skipIterable) {
                continue;
            }

            $unionTypesNodes[] = $this->phpStanStaticTypeMapper->mapToPHPStanPhpDocTypeNode($unionedType);
        }

        $unionTypesNodes = array_unique($unionTypesNodes);
        return new BracketsAwareUnionTypeNode($unionTypesNodes);
    }

    /**
     * @param UnionType $type
     */
    public function mapToPhpParserNode(Type $type, ?string $kind = null): ?Node
    {
        $arrayNode = $this->matchArrayTypes($type);
        if ($arrayNode !== null) {
            return $arrayNode;
        }

        if ($this->boolUnionTypeAnalyzer->isNullableBoolUnionType(
            $type
        ) && ! $this->phpVersionProvider->isAtLeastPhpVersion(PhpVersionFeature::UNION_TYPES)) {
            return new NullableType(new Name('bool'));
        }

        // special case for nullable
        $nullabledType = $this->matchTypeForNullableUnionType($type);
        if (! $nullabledType instanceof Type) {
            // use first unioned type in case of unioned object types
            return $this->matchTypeForUnionedObjectTypes($type);
        }

        // void cannot be nullable
        if ($nullabledType instanceof VoidType) {
            return null;
        }

        $nullabledTypeNode = $this->phpStanStaticTypeMapper->mapToPhpParserNode($nullabledType);
        if (! $nullabledTypeNode instanceof Node) {
            return null;
        }

        if ($nullabledTypeNode instanceof NullableType) {
            return $nullabledTypeNode;
        }

        if ($nullabledTypeNode instanceof PhpParserUnionType) {
            throw new ShouldNotHappenException();
        }

        return new NullableType($nullabledTypeNode);
    }

    /**
     * @param UnionType $type
     */
    public function mapToDocString(Type $type, ?Type $parentType = null): string
    {
        $docStrings = [];

        foreach ($type->getTypes() as $unionedType) {
            $docStrings[] = $this->phpStanStaticTypeMapper->mapToDocString($unionedType);
        }

        // remove empty values, e.g. void/iterable
        $docStrings = array_unique($docStrings);
        $docStrings = array_filter($docStrings);

        return implode('|', $docStrings);
    }

    private function shouldSkipIterable(UnionType $unionType): bool
    {
        $unionTypeAnalysis = $this->unionTypeAnalyzer->analyseForNullableAndIterable($unionType);
        if (! $unionTypeAnalysis instanceof UnionTypeAnalysis) {
            return false;
        }
        if (! $unionTypeAnalysis->hasIterable()) {
            return false;
        }
        return $unionTypeAnalysis->hasArray();
    }

    /**
     * @return Name|NullableType|null
     */
    private function matchArrayTypes(UnionType $unionType): ?Node
    {
        $unionTypeAnalysis = $this->unionTypeAnalyzer->analyseForNullableAndIterable($unionType);
        if (! $unionTypeAnalysis instanceof UnionTypeAnalysis) {
            return null;
        }

        $type = $unionTypeAnalysis->hasIterable() ? 'iterable' : 'array';
        if ($unionTypeAnalysis->isNullableType()) {
            return new NullableType($type);
        }

        return new Name($type);
    }

    private function matchTypeForNullableUnionType(UnionType $unionType): ?Type
    {
        if (count($unionType->getTypes()) !== 2) {
            return null;
        }

        $firstType = $unionType->getTypes()[0];
        $secondType = $unionType->getTypes()[1];

        if ($firstType instanceof NullType) {
            return $secondType;
        }

        if ($secondType instanceof NullType) {
            return $firstType;
        }

        return null;
    }

    /**
     * @return Name|FullyQualified|PhpParserUnionType|null
     */
    private function matchTypeForUnionedObjectTypes(UnionType $unionType): ?Node
    {
        $phpParserUnionType = $this->matchPhpParserUnionType($unionType);
        if ($phpParserUnionType !== null) {
            if (! $this->phpVersionProvider->isAtLeastPhpVersion(PhpVersionFeature::UNION_TYPES)) {
                // maybe all one type?
                if ($this->boolUnionTypeAnalyzer->isBoolUnionType($unionType)) {
                    return new Name('bool');
                }

                return null;
            }

            return $phpParserUnionType;
        }

        if ($this->boolUnionTypeAnalyzer->isBoolUnionType($unionType)) {
            return new Name('bool');
        }

        // the type should be compatible with all other types, e.g. A extends B, B
        $compatibleObjectType = $this->resolveCompatibleObjectCandidate($unionType);
        if (! $compatibleObjectType instanceof ObjectType) {
            return null;
        }

        return new FullyQualified($compatibleObjectType->getClassName());
    }

    private function matchPhpParserUnionType(UnionType $unionType): ?PhpParserUnionType
    {
        if (! $this->phpVersionProvider->isAtLeastPhpVersion(PhpVersionFeature::UNION_TYPES)) {
            return null;
        }

        $phpParserUnionedTypes = [];

        foreach ($unionType->getTypes() as $unionedType) {
            // void type is not allowed in union
            if ($unionedType instanceof VoidType) {
                return null;
            }

            /** @var Identifier|Name|null $phpParserNode */
            $phpParserNode = $this->phpStanStaticTypeMapper->mapToPhpParserNode($unionedType);
            if ($phpParserNode === null) {
                return null;
            }

            $phpParserUnionedTypes[] = $phpParserNode;
        }

        return new PhpParserUnionType($phpParserUnionedTypes);
    }

    private function resolveCompatibleObjectCandidate(UnionType $unionType): ?TypeWithClassName
    {
        if ($this->doctrineTypeAnalyzer->isDoctrineCollectionWithIterableUnionType($unionType)) {
            return new ObjectType('Doctrine\Common\Collections\Collection');
        }

        if (! $this->unionTypeAnalyzer->hasTypeClassNameOnly($unionType)) {
            return null;
        }

        $sharedTypeWithClassName = $this->matchTwoObjectTypes($unionType);
        if ($sharedTypeWithClassName instanceof TypeWithClassName) {
            return $this->correctObjectType($sharedTypeWithClassName);
        }
        // find least common denominator
        return $this->unionTypeCommonTypeNarrower->narrowToSharedObjectType($unionType);
    }

    private function matchTwoObjectTypes(UnionType $unionType): ?TypeWithClassName
    {
        /** @var TypeWithClassName $unionedType */
        foreach ($unionType->getTypes() as $unionedType) {
            /** @var TypeWithClassName $nestedUnionedType */
            foreach ($unionType->getTypes() as $nestedUnionedType) {
                if (! $this->areTypeWithClassNamesRelated($unionedType, $nestedUnionedType)) {
                    continue 2;
                }
            }

            return $unionedType;
        }

        return null;
    }

    private function areTypeWithClassNamesRelated(TypeWithClassName $firstType, TypeWithClassName $secondType): bool
    {
        if ($firstType->accepts($secondType, false)->yes()) {
            return true;
        }

        return $secondType->accepts($firstType, false)
            ->yes();
    }

    private function correctObjectType(TypeWithClassName $typeWithClassName): TypeWithClassName
    {
        if ($typeWithClassName->getClassName() === NodeAbstract::class) {
            return new ObjectType('PhpParser\Node');
        }

        if ($typeWithClassName->getClassName() === AbstractRector::class) {
            return new ObjectType('Rector\Core\Contract\Rector\RectorInterface');
        }

        return $typeWithClassName;
    }
}
