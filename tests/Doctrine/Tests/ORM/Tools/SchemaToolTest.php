<?php

namespace Doctrine\Tests\ORM\Tools;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Tools\Event\GenerateSchemaEventArgs;
use Doctrine\ORM\Tools\Event\GenerateSchemaTableEventArgs;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\ORM\Tools\ToolEvents;
use Doctrine\Tests\Models\CMS\CmsAddress;
use Doctrine\Tests\Models\CMS\CmsArticle;
use Doctrine\Tests\Models\CMS\CmsComment;
use Doctrine\Tests\Models\CMS\CmsEmployee;
use Doctrine\Tests\Models\CMS\CmsGroup;
use Doctrine\Tests\Models\CMS\CmsPhonenumber;
use Doctrine\Tests\Models\CMS\CmsUser;
use Doctrine\Tests\Models\CompositeKeyInheritance\JoinedDerivedChildClass;
use Doctrine\Tests\Models\CompositeKeyInheritance\JoinedDerivedIdentityClass;
use Doctrine\Tests\Models\CompositeKeyInheritance\JoinedDerivedRootClass;
use Doctrine\Tests\Models\Forum\ForumAvatar;
use Doctrine\Tests\Models\Forum\ForumBoard;
use Doctrine\Tests\Models\Forum\ForumCategory;
use Doctrine\Tests\Models\Forum\ForumUser;
use Doctrine\Tests\Models\NullDefault\NullDefaultColumn;
use Doctrine\Tests\OrmTestCase;

class SchemaToolTest extends OrmTestCase
{
    public function testAddUniqueIndexForUniqueFieldAnnotation()
    {
        $em = $this->_getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $classes = [
            $em->getClassMetadata(CmsAddress::class),
            $em->getClassMetadata(CmsArticle::class),
            $em->getClassMetadata(CmsComment::class),
            $em->getClassMetadata(CmsEmployee::class),
            $em->getClassMetadata(CmsGroup::class),
            $em->getClassMetadata(CmsPhonenumber::class),
            $em->getClassMetadata(CmsUser::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $this->assertTrue($schema->hasTable('cms_users'), "Table cms_users should exist.");
        $this->assertTrue($schema->getTable('cms_users')->columnsAreIndexed(['username']), "username column should be indexed.");
    }

    public function testAnnotationOptionsAttribute()
    {
        $em = $this->_getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $classes = [
            $em->getClassMetadata(TestEntityWithAnnotationOptionsAttribute::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $expected = ['foo' => 'bar', 'baz' => ['key' => 'val']];

        $this->assertEquals($expected, $schema->getTable('TestEntityWithAnnotationOptionsAttribute')->getOptions(), "options annotation are passed to the tables options");
        $this->assertEquals($expected, $schema->getTable('TestEntityWithAnnotationOptionsAttribute')->getColumn('test')->getCustomSchemaOptions(), "options annotation are passed to the columns customSchemaOptions");
    }

    /**
     * @group DDC-200
     */
    public function testPassColumnDefinitionToJoinColumn()
    {
        $customColumnDef = "MEDIUMINT(6) UNSIGNED NOT NULL";

        $em = $this->_getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $avatar = $em->getClassMetadata(ForumAvatar::class);
        $avatar->fieldMappings['id']['columnDefinition'] = $customColumnDef;
        $user = $em->getClassMetadata(ForumUser::class);

        $classes = [$avatar, $user];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $this->assertTrue($schema->hasTable('forum_users'));
        $table = $schema->getTable("forum_users");
        $this->assertTrue($table->hasColumn('avatar_id'));
        $this->assertEquals($customColumnDef, $table->getColumn('avatar_id')->getColumnDefinition());
    }

    public function testPassColumnOptionsToJoinColumn()
    {
        $em = $this->_getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $category = $em->getClassMetadata(ForumCategory::class);
        $board = $em->getClassMetadata(ForumBoard::class);

        $classes = array($category, $board);

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $this->assertTrue($schema->hasTable('forum_categories'));
        $this->assertTrue($schema->hasTable('forum_boards'));

        $tableCategory = $schema->getTable('forum_categories');
        $tableBoard = $schema->getTable('forum_boards');

        $this->assertTrue($tableBoard->hasColumn('category_id'));

        $this->assertSame(
         $tableCategory->getColumn('id')->getFixed(),
         $tableBoard->getColumn('category_id')->getFixed(),
         'Foreign key/join column should have the same value of option `fixed` as the referenced column'
        );

        $this->assertEquals(
         $tableCategory->getColumn('id')->getCustomSchemaOptions(),
         $tableBoard->getColumn('category_id')->getCustomSchemaOptions(),
         'Foreign key/join column should have the same custom options as the referenced column'
        );

        $this->assertNotEmpty(
            $tableBoard->getColumn('category_id')->getCustomSchemaOptions(),
            'There should be custom options set on the column like the collation'
        );
    }

    /**
     * @group DDC-283
     */
    public function testPostGenerateEvents()
    {
        $listener = new GenerateSchemaEventListener();

        $em = $this->_getTestEntityManager();
        $em->getEventManager()->addEventListener(
            [ToolEvents::postGenerateSchemaTable, ToolEvents::postGenerateSchema], $listener
        );
        $schemaTool = new SchemaTool($em);

        $classes = [
            $em->getClassMetadata(CmsAddress::class),
            $em->getClassMetadata(CmsArticle::class),
            $em->getClassMetadata(CmsComment::class),
            $em->getClassMetadata(CmsEmployee::class),
            $em->getClassMetadata(CmsGroup::class),
            $em->getClassMetadata(CmsPhonenumber::class),
            $em->getClassMetadata(CmsUser::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $this->assertEquals(count($classes), $listener->tableCalls);
        $this->assertTrue($listener->schemaCalled);
    }

    public function testNullDefaultNotAddedToCustomSchemaOptions()
    {
        $em = $this->_getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $customSchemaOptions = $schemaTool->getSchemaFromMetadata([$em->getClassMetadata(NullDefaultColumn::class)])
            ->getTable('NullDefaultColumn')
            ->getColumn('nullDefault')
            ->getCustomSchemaOptions();

        $this->assertSame([], $customSchemaOptions);
    }

    /**
     * @group DDC-3671
     */
    public function testSchemaHasProperIndexesFromUniqueConstraintAnnotation()
    {
        $em         = $this->_getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $classes    = [
            $em->getClassMetadata(UniqueConstraintAnnotationModel::class),
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $this->assertTrue($schema->hasTable('unique_constraint_annotation_table'));
        $table = $schema->getTable('unique_constraint_annotation_table');

        $this->assertEquals(2, count($table->getIndexes()));
        $this->assertTrue($table->hasIndex('primary'));
        $this->assertTrue($table->hasIndex('uniq_hash'));
    }

    public function testRemoveUniqueIndexOverruledByPrimaryKey()
    {
        $em         = $this->_getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $classes    = [
            $em->getClassMetadata(FirstEntity::class),
            $em->getClassMetadata(SecondEntity::class)
        ];

        $schema = $schemaTool->getSchemaFromMetadata($classes);

        $this->assertTrue($schema->hasTable('first_entity'), "Table first_entity should exist.");

        $indexes = $schema->getTable('first_entity')->getIndexes();

        $this->assertCount(1, $indexes, "there should be only one index");
        $this->assertTrue(current($indexes)->isPrimary(), "index should be primary");
    }

    public function testSetDiscriminatorColumnWithoutLength() : void
    {
        $em         = $this->_getTestEntityManager();
        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getClassMetadata(FirstEntity::class);

        $metadata->setInheritanceType(ClassMetadata::INHERITANCE_TYPE_SINGLE_TABLE);
        $metadata->setDiscriminatorColumn(['name' => 'discriminator', 'type' => 'string']);

        $schema = $schemaTool->getSchemaFromMetadata([$metadata]);

        $this->assertTrue($schema->hasTable('first_entity'));
        $table = $schema->getTable('first_entity');

        $this->assertTrue($table->hasColumn('discriminator'));
        $column = $table->getColumn('discriminator');

        $this->assertEquals(255, $column->getLength());
    }

    public function testDerivedCompositeKey() : void
    {
        $em         = $this->_getTestEntityManager();
        $schemaTool = new SchemaTool($em);

        $schema = $schemaTool->getSchemaFromMetadata(
            [
                $em->getClassMetadata(JoinedDerivedIdentityClass::class),
                $em->getClassMetadata(JoinedDerivedRootClass::class),
                $em->getClassMetadata(JoinedDerivedChildClass::class),
            ]
        );

        self::assertTrue($schema->hasTable('joined_derived_identity'));
        self::assertTrue($schema->hasTable('joined_derived_root'));
        self::assertTrue($schema->hasTable('joined_derived_child'));

        $rootTable = $schema->getTable('joined_derived_root');
        self::assertNotNull($rootTable->getPrimaryKey());
        self::assertSame(['keyPart1_id', 'keyPart2'], $rootTable->getPrimaryKey()->getColumns());

        $childTable = $schema->getTable('joined_derived_child');
        self::assertNotNull($childTable->getPrimaryKey());
        self::assertSame(['keyPart1_id', 'keyPart2'], $childTable->getPrimaryKey()->getColumns());

        $childTableForeignKeys = $childTable->getForeignKeys();

        self::assertCount(2, $childTableForeignKeys);

        $expectedColumns = [
            'joined_derived_identity' => [['keyPart1_id'], ['id']],
            'joined_derived_root'     => [['keyPart1_id', 'keyPart2'], ['keyPart1_id', 'keyPart2']],
        ];

        foreach ($childTableForeignKeys as $foreignKey) {
            self::assertArrayHasKey($foreignKey->getForeignTableName(), $expectedColumns);

            [$localColumns, $foreignColumns] = $expectedColumns[$foreignKey->getForeignTableName()];

            self::assertSame($localColumns, $foreignKey->getLocalColumns());
            self::assertSame($foreignColumns, $foreignKey->getForeignColumns());
        }
    }
}

/**
 * @Entity
 * @Table(options={"foo": "bar", "baz": {"key": "val"}})
 */
class TestEntityWithAnnotationOptionsAttribute
{
    /** @Id @Column */
    private $id;

    /**
     * @Column(type="string", options={"foo": "bar", "baz": {"key": "val"}})
     */
    private $test;
}

class GenerateSchemaEventListener
{
    public $tableCalls = 0;
    public $schemaCalled = false;

    public function postGenerateSchemaTable(GenerateSchemaTableEventArgs $eventArgs)
    {
        $this->tableCalls++;
    }

    public function postGenerateSchema(GenerateSchemaEventArgs $eventArgs)
    {
        $this->schemaCalled = true;
    }
}

/**
 * @Entity
 * @Table(name="unique_constraint_annotation_table", uniqueConstraints={
 *   @UniqueConstraint(name="uniq_hash", columns={"hash"})
 * })
 */
class UniqueConstraintAnnotationModel
{
    /** @Id @Column */
    private $id;

    /**
     * @Column(name="hash", type="string", length=8, nullable=false, unique=true)
     */
    private $hash;
}

/**
 * @Entity
 * @Table(name="first_entity")
 */
class FirstEntity
{
    /**
     * @Id
     * @Column(name="id")
     */
    public $id;

    /**
     * @OneToOne(targetEntity="SecondEntity")
     * @JoinColumn(name="id", referencedColumnName="fist_entity_id")
     */
    public $secondEntity;

    /**
     * @Column(name="name")
     */
    public $name;
}

/**
 * @Entity
 * @Table(name="second_entity")
 */
class SecondEntity
{
    /**
     * @Id
     * @Column(name="fist_entity_id")
     */
    public $fist_entity_id;

    /**
     * @Column(name="name")
     */
    public $name;
}
