<?php

/*
 * This file is part of Sedona Back-Office Bundles.
 *
 * (c) Sedona <http://www.sedona.fr/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sedona\SBORuntimeBundle\Datatable\Data;

use Sedona\SBORuntimeBundle\ClassUtils;
use Sg\DatatablesBundle\Datatable\View\DatatableViewInterface;
use Sg\DatatablesBundle\Datatable\Column\AbstractColumn;
use Sg\DatatablesBundle\Datatable\Column\ImageColumn;
use Sg\DatatablesBundle\Datatable\Column\GalleryColumn;
use Sg\DatatablesBundle\Datatable\Data\DatatableQuery as DatatableQueryBase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\PropertyAccess\Exception\InvalidArgumentException;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Mapping\MappingException;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query\Expr\Andx;
use Doctrine\ORM\Query;
use Doctrine\ORM\Tools\Pagination\Paginator;

class DatatableQuery extends DatatableQueryBase
{
    /**
     * @var Serializer
     */
    private $serializer;

    /**
     * @var array
     */
    private $requestParams;

    /**
     * @var DatatableViewInterface
     */
    private $datatableView;

    /**
     * @var string
     */
    private $entity;

    /**
     * @var bool
     */
    private $individualFiltering;

    /**
     * @var EntityManager
     */
    private $em;

    /**
     * @var ClassMetadata
     */
    private $metadata;

    /**
     * @var string
     */
    private $tableName;

    /**
     * @var mixed
     */
    private $rootEntityIdentifier;

    /**
     * @var QueryBuilder
     */
    private $qb;

    /**
     * @var array
     */
    private $selectColumns;

    /**
     * @var array
     */
    private $virtualColumns;

    /**
     * @var array
     */
    private $joins;

    /**
     * @var array
     */
    private $searchColumns;

    /**
     * @var array
     */
    private $orderColumns;

    /**
     * @var array
     */
    private $callbacks;

    /**
     * @var callable
     */
    private $lineFormatter;

    /**
     * @var AbstractColumn[]
     */
    private $columns;

    /**
     * @var array
     */
    private $configs;

    /**
     * @var Twig_Environment
     */
    private $twig;

    /**
     * @var bool
     */
    private $imagineBundle;

    /**
     * @var bool
     */
    private $doctrineExtensions;

    /**
     * The locale.
     *
     * @var string
     */
    private $locale;

    /**
     * @var PropertyAccessorInterface
     */
    private $propertyAccessor;

    //-------------------------------------------------
    // Ctor.
    //-------------------------------------------------

    /**
     * Ctor.
     *
     * @param Serializer             $serializer
     * @param array                  $requestParams
     * @param DatatableViewInterface $datatableView
     * @param array                  $configs
     * @param \Twig_Environment      $twig
     * @param bool                   $imagineBundle
     * @param bool                   $doctrineExtensions
     * @param string                 $locale
     *
     * @throws \Exception
     */
    public function __construct(
        Serializer $serializer,
        array $requestParams,
        DatatableViewInterface $datatableView,
        array $configs,
        \Twig_Environment $twig,
        $imagineBundle,
        $doctrineExtensions,
        $locale
    ) {
        $this->propertyAccessor = PropertyAccess::createPropertyAccessorBuilder()->enableMagicCall()->getPropertyAccessor();

        $this->serializer = $serializer;
        $this->requestParams = $requestParams;
        $this->datatableView = $datatableView;

        $this->individualFiltering = $this->datatableView->getOptions()->getIndividualFiltering();

        $this->entity = $this->datatableView->getEntity();
        $this->em = $this->datatableView->getEntityManager();
        $this->metadata = $this->getMetadata($this->entity);
        $this->tableName = $this->getTableName($this->metadata);
        $this->rootEntityIdentifier = $this->getIdentifier($this->metadata);
        $this->qb = $this->em->createQueryBuilder();

        $this->selectColumns = array();
        $this->virtualColumns = $datatableView->getColumnBuilder()->getVirtualColumns();
        $this->joins = array();
        $this->searchColumns = array();
        $this->orderColumns = array();
        $this->callbacks = array();
        $this->columns = $datatableView->getColumnBuilder()->getColumns();

        $this->configs = $configs;

        $this->twig = $twig;
        $this->imagineBundle = $imagineBundle;
        $this->doctrineExtensions = $doctrineExtensions;
        $this->locale = $locale;

        $this->setLineFormatter();
        $this->setupColumnArrays();
    }

    //-------------------------------------------------
    // Setup query
    //-------------------------------------------------

    /**
     * Setup column arrays.
     *
     * @return $this
     */
    private function setupColumnArrays()
    {
        /* Example:
              SELECT
                  partial fos_user.{id},
                  partial posts_comments.{title,id},
                  partial posts.{id,title}
              FROM
                  AppBundle\Entity\User fos_user
              LEFT JOIN
                  fos_user.posts posts
              LEFT JOIN
                  posts.comments posts_comments
              ORDER BY
                  posts_comments.title asc
         */

        $this->selectColumns[$this->tableName][] = $this->rootEntityIdentifier;

        foreach ($this->columns as $key => $column) {
            $data = $column->getDql();

            if (true === $this->isSelectColumn($data)) {
                if (false === $this->isAssociation($data)) {
                    if (!$this->metadata->hasField($data)) {
                        $reflection = $this->metadata->getReflectionClass();
                        if (
                            ClassUtils::hasTrait($reflection, 'Knp\DoctrineBehaviors\Model\Translatable\Translatable', true) &&
                            $this->em->getClassMetadata(call_user_func([$reflection->getName(), 'getTranslationEntityClass']))->hasField($data)
                        ) {
                            $this->setIdentifierFromAssociation('translations');

                            $this->selectColumns['translations'][] = $data;
                            $this->joins[$this->tableName.'.'.'translations'] = 'translations';
                            $this->addSearchOrderColumn($key, 'translations', $data);

                            continue;
                        } else {
                            if (false === array_key_exists($data, $this->selectColumns)) {
                                $this->setIdentifierFromAssociation($data);
                            }

                            $this->selectColumns[$data][] = 'id';
                            $this->joins[$this->tableName.'.'.$data] = $data;
                            $this->addSearchOrderColumn($key, $data, 'id');

                            continue;
                        }
                    }

                    $this->addSearchOrderColumn($key, $this->tableName, $data);
                    if ($data !== $this->rootEntityIdentifier) {
                        $this->selectColumns[$this->tableName][] = $data;
                    }
                } else {
                    $array = explode('.', $data);
                    $count = count($array);

                    if ($count > 2) {
                        $replaced = str_replace('.', '_', $data);
                        $parts = explode('_', $replaced);
                        $last = array_pop($parts);
                        $select = implode('_', $parts);
                        $join = str_replace('_', '.', $select);

                        // id root-table
                        if (false === array_key_exists($array[0], $this->selectColumns)) {
                            $this->setIdentifierFromAssociation($array[0]);
                        }
                        $this->joins[$this->tableName.'.'.$array[0]] = $array[0];

                        // id association
                        if (false === array_key_exists($select, $this->selectColumns)) {
                            $this->setIdentifierFromAssociation($parts, $select);
                        }
                        $this->joins[$join] = $select;

                        $this->selectColumns[$select][] = $last;
                        $this->addSearchOrderColumn($key, $select, $last);
                    } else {
                        if (false === array_key_exists($array[0], $this->selectColumns)) {
                            $this->setIdentifierFromAssociation($array[0]);
                        }

                        $this->selectColumns[$array[0]][] = $array[1];
                        $this->joins[$this->tableName.'.'.$array[0]] = $array[0];
                        $this->addSearchOrderColumn($key, $array[0], $array[1]);
                    }
                }
            } else {
                $this->orderColumns[] = null;
                $this->searchColumns[] = null;
            }
        }

        return $this;
    }

    /**
     * Build query.
     *
     * @return $this
     */
    public function buildQuery()
    {
        $this->setSelectFrom();
        $this->setLeftJoins($this->qb);
        $this->setWhere($this->qb);
        $this->setWhereResultCallback($this->qb);
        $this->setWhereAllCallback($this->qb);
        $this->setOrderBy();
        $this->setLimit();

        return $this;
    }

    /**
     * Get query.
     *
     * @return QueryBuilder
     */
    public function getQuery()
    {
        return $this->qb;
    }

    /**
     * Set query.
     *
     * @param QueryBuilder $qb
     *
     * @return $this
     */
    public function setQuery(QueryBuilder $qb)
    {
        $this->qb = $qb;

        return $this;
    }

    //-------------------------------------------------
    // Callbacks
    //-------------------------------------------------

    /**
     * Add the where-result function.
     *
     * @param $callback
     *
     * @return $this
     */
    public function addWhereResult($callback)
    {
        $this->callbacks['WhereResult'][] = $callback;

        return $this;
    }

    /**
     * Add the where-all function.
     *
     * @param $callback
     *
     * @return $this
     */
    public function addWhereAll($callback)
    {
        $this->callbacks['WhereAll'][] = $callback;

        return $this;
    }

    /**
     * Set the line formatter function.
     *
     * @return $this
     */
    private function setLineFormatter()
    {
        $this->lineFormatter = $this->datatableView->getLineFormatter();

        return $this;
    }

    /**
     * Set where result callback.
     *
     * @param QueryBuilder $qb
     *
     * @return $this
     */
    private function setWhereResultCallback(QueryBuilder $qb)
    {
        if (!empty($this->callbacks['WhereResult'])) {
            foreach ($this->callbacks['WhereResult'] as $callback) {
                $callback($qb);
            }
        }

        return $this;
    }

    /**
     * Set where all callback.
     *
     * @param QueryBuilder $qb
     *
     * @return $this
     */
    private function setWhereAllCallback(QueryBuilder $qb)
    {
        if (!empty($this->callbacks['WhereAll'])) {
            foreach ($this->callbacks['WhereAll'] as $callback) {
                $callback($qb);
            }
        }

        return $this;
    }

    //-------------------------------------------------
    // Build a query
    //-------------------------------------------------

    /**
     * Set select from.
     *
     * @return $this
     */
    private function setSelectFrom()
    {
        foreach ($this->selectColumns as $key => $value) {
            $this->qb->addSelect($key);
        }

        $this->qb->from($this->entity, $this->tableName);

        return $this;
    }

    /**
     * Set leftJoins.
     *
     * @param QueryBuilder $qb
     *
     * @return $this
     */
    private function setLeftJoins(QueryBuilder $qb)
    {
        foreach ($this->joins as $key => $value) {
            $qb->leftJoin($key, $value);
        }

        return $this;
    }

    /**
     * Searching / Filtering.
     * Construct the WHERE clause for server-side processing SQL query.
     *
     * @param QueryBuilder $qb
     *
     * @return $this
     */
    private function setWhere(QueryBuilder $qb)
    {
        $globalSearch = $this->requestParams['search']['value'];

        // global filtering
        if ('' != $globalSearch) {
            $orExpr = $qb->expr()->orX();

            foreach ($this->columns as $key => $column) {
                if (true === $this->isSearchColumn($column)) {
                    $searchField = $this->searchColumns[$key];
                    $orExpr->add($qb->expr()->like($searchField, '?'.$key));
                    $qb->setParameter($key, '%'.$globalSearch.'%');
                }
            }

            $qb->where($orExpr);
        }

        // individual filtering
        if (true === $this->individualFiltering) {
            $andExpr = $qb->expr()->andX();

            $i = 100;

            foreach ($this->columns as $key => $column) {
                if (true === $this->isSearchColumn($column)) {
                    $searchType = $column->getSearchType();
                    $searchField = $this->searchColumns[$key];
                    $searchValue = $this->requestParams['columns'][$key]['search']['value'];
                    $searchRange = $this->requestParams['columns'][$key]['name'] === 'daterange';
                    if ('' != $searchValue && 'null' != $searchValue) {
                        if ($searchRange) {
                            list($_dateStart, $_dateEnd) = explode(' - ', $searchValue);
                            $dateStart = new \DateTime($_dateStart);
                            $dateEnd = new \DateTime($_dateEnd);
                            $dateEnd->setTime(23, 59, 59);

                            $k = $i + 1;
                            $andExpr->add($qb->expr()->between($searchField, '?'.$i, '?'.$k));
                            $qb->setParameter($i, $dateStart->format('Y-m-d H:i:s'));
                            $qb->setParameter($k, $dateEnd->format('Y-m-d H:i:s'));
                            $i += 2;
                        } else {
                            $andExpr = $this->addCondition($andExpr, $qb, $searchType, $searchField, $searchValue, $i);
                            ++$i;
                        }
                    }
                }
            }

            if ($andExpr->count() > 0) {
                $qb->andWhere($andExpr);
            }
        }

        return $this;
    }

    /**
     * Add a condition.
     *
     * @param Andx         $andExpr
     * @param QueryBuilder $pivot
     * @param string       $searchType
     * @param string       $searchField
     * @param string       $searchValue
     * @param int          $i
     *
     * @return Andx
     */
    private function addCondition(Andx $andExpr, QueryBuilder $pivot, $searchType, $searchField, $searchValue, $i)
    {
        switch ($searchType) {
            case 'like':
                $andExpr->add($pivot->expr()->like($searchField, '?'.$i));
                $pivot->setParameter($i, '%'.$searchValue.'%');
                break;
            case 'notLike':
                $andExpr->add($pivot->expr()->notLike($searchField, '?'.$i));
                $pivot->setParameter($i, '%'.$searchValue.'%');
                break;
            case 'eq':
                $andExpr->add($pivot->expr()->eq($searchField, '?'.$i));
                $pivot->setParameter($i, $searchValue);
                break;
            case 'neq':
                $andExpr->add($pivot->expr()->neq($searchField, '?'.$i));
                $pivot->setParameter($i, $searchValue);
                break;
            case 'lt':
                $andExpr->add($pivot->expr()->lt($searchField, '?'.$i));
                $pivot->setParameter($i, $searchValue);
                break;
            case 'lte':
                $andExpr->add($pivot->expr()->lte($searchField, '?'.$i));
                $pivot->setParameter($i, $searchValue);
                break;
            case 'gt':
                $andExpr->add($pivot->expr()->gt($searchField, '?'.$i));
                $pivot->setParameter($i, $searchValue);
                break;
            case 'gte':
                $andExpr->add($pivot->expr()->gte($searchField, '?'.$i));
                $pivot->setParameter($i, $searchValue);
                break;
            case 'in':
                $andExpr->add($pivot->expr()->in($searchField, '?'.$i));
                $pivot->setParameter($i, explode(',', $searchValue));
                break;
            case 'notIn':
                $andExpr->add($pivot->expr()->notIn($searchField, '?'.$i));
                $pivot->setParameter($i, explode(',', $searchValue));
                break;
            case 'isNull':
                $andExpr->add($pivot->expr()->isNull($searchField));
                break;
            case 'isNotNull':
                $andExpr->add($pivot->expr()->isNotNull($searchField));
                break;
        }

        return $andExpr;
    }

    /**
     * Ordering.
     * Construct the ORDER BY clause for server-side processing SQL query.
     *
     * @return $this
     */
    private function setOrderBy()
    {
        if (isset($this->requestParams['order']) && count($this->requestParams['order'])) {
            $counter = count($this->requestParams['order']);

            for ($i = 0; $i < $counter; ++$i) {
                $columnIdx = (integer) $this->requestParams['order'][$i]['column'];
                $requestColumn = $this->requestParams['columns'][$columnIdx];

                if ('true' == $requestColumn['orderable']) {
                    $this->qb->addOrderBy(
                        $this->orderColumns[$columnIdx],
                        $this->requestParams['order'][$i]['dir']
                    );
                }
            }
        }

        return $this;
    }

    /**
     * Paging.
     * Construct the LIMIT clause for server-side processing SQL query.
     *
     * @return $this
     */
    private function setLimit()
    {
        if (isset($this->requestParams['start']) && -1 != $this->requestParams['length']) {
            $this->qb->setFirstResult($this->requestParams['start'])->setMaxResults($this->requestParams['length']);
        }

        return $this;
    }

    //-------------------------------------------------
    // Results
    //-------------------------------------------------

    /**
     * Query results before filtering.
     *
     * @param int $rootEntityIdentifier
     *
     * @return int
     */
    private function getCountAllResults($rootEntityIdentifier)
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('count(distinct '.$this->tableName.'.'.$rootEntityIdentifier.')');
        $qb->from($this->entity, $this->tableName);

        $this->setLeftJoins($qb);
        $this->setWhereAllCallback($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Query results after filtering.
     *
     * @param int $rootEntityIdentifier
     *
     * @return int
     */
    private function getCountFilteredResults($rootEntityIdentifier)
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select('count(distinct '.$this->tableName.'.'.$rootEntityIdentifier.')');
        $qb->from($this->entity, $this->tableName);

        $this->setLeftJoins($qb);
        $this->setWhere($qb);
        $this->setWhereAllCallback($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Constructs a Query instance.
     *
     * @return Query
     *
     * @throws \Exception
     */
    private function execute()
    {
        $query = $this->qb->getQuery();

        if (true === $this->configs['translation_query_hints']) {
            if (true === $this->doctrineExtensions) {
                $query->setHint(
                    \Doctrine\ORM\Query::HINT_CUSTOM_OUTPUT_WALKER,
                    'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker'
                );

                $query->setHint(
                    \Gedmo\Translatable\TranslatableListener::HINT_TRANSLATABLE_LOCALE,
                    $this->locale
                );

                $query->setHint(
                    \Gedmo\Translatable\TranslatableListener::HINT_FALLBACK,
                    1
                );
            } else {
                throw new \Exception('execute(): "DoctrineExtensions" does not exist.');
            }
        }

        $query->setHydrationMode(Query::HYDRATE_OBJECT);

        return $query;
    }

    //-------------------------------------------------
    // Response
    //-------------------------------------------------

    /**
     * Normalizes an entity path into an array.
     * Each entity of each level will have its id set.
     * All values are retrieved using a property accessor with magic call activated.
     *
     * @param array  $data
     * @param object $entity Entity to normalize
     * @param string $path   Path to set in the array
     *
     * @return array Normalized data
     */
    private function entityFieldToArray(array $data, $entity, $path)
    {
        $parts = explode('.', $path, 2);

        if (count($parts) === 1) {
            if ($entity instanceof \Traversable) {
                $data = [];

                foreach ($entity as $item) {
                    $id = $this->em->getClassMetadata(get_class($item))->getSingleIdentifierColumnName();

                    $data[] = [
                        $id => $this->propertyAccessor->getValue($item, $id),
                        $parts[0] => $this->propertyAccessor->getValue($item, $parts[0]),
                    ];
                }
            } else {
                if (count($data) === 0) {
                    $data = [];
                    $id = $this->em->getClassMetadata(get_class($entity))->getSingleIdentifierColumnName();
                    $data[$id] = $this->propertyAccessor->getValue($entity, $id);
                }

                $data[$parts[0]] = $this->propertyAccessor->getValue($entity, $parts[0]);
            }

            return $data;
        } else {
            $data[$parts[0]] = $this->entityFieldToArray(
                isset($data[$parts[0]]) ? $data[$parts[0]] : [],
                $this->propertyAccessor->getValue($entity, $parts[0]),
                $parts[1]
            );

            return $data;
        }
    }

    public function getResponse($buildQuery = true)
    {
        false === $buildQuery ?: $this->buildQuery();

        $fresults = new Paginator($this->execute(), true);
        $fresults->setUseOutputWalkers(true);
        $output = array('data' => array());

        foreach ($fresults as $result) {
            $item = [];
            $item[$this->rootEntityIdentifier] = $this->propertyAccessor->getValue($result, $this->rootEntityIdentifier);
            foreach ($this->columns as $column) {
                $data = $column->getDql();
                if (!$this->isSelectColumn($data)) {
                    continue;
                }

                $item = $this->entityFieldToArray($item, $result, $data);
            }

            // Line formatter
            if (is_callable($this->lineFormatter)) {
                $callable = $this->lineFormatter;
                $item = call_user_func($callable, $item);
            }

            // Images
            foreach ($this->columns as $column) {
                $data = $column->getDql();

                /** @var ImageColumn $column */
                if ('image' === $column->getAlias()) {
                    if (true === $this->imagineBundle) {
                        $item[$data] = $this->renderImage($item[$data], $column);
                    } else {
                        $item[$data] = $this->twig->render(
                            'SgDatatablesBundle:Helper:render_image.html.twig',
                            array(
                                'image_name' => $item[$data],
                                'path' => $column->getRelativePath(),
                            )
                        );
                    }
                }

                /** @var GalleryColumn $column */
                if ('gallery' === $column->getAlias()) {
                    $fields = explode('.', $data);

                    if (true === $this->imagineBundle) {
                        $galleryImages = '';
                        $counter = 0;
                        $images = count($item[$fields[0]]);
                        if (0 === $images) {
                            $item[$fields[0]] = $this->renderImage(null, $column);
                        } else {
                            foreach ($item[$fields[0]] as $image) {
                                $galleryImages = $galleryImages.$this->renderImage($image[$fields[1]], $column);
                                if (++$counter == $column->getViewLimit()) {
                                    break;
                                }
                            }
                            $item[$fields[0]] = $galleryImages;
                        }
                    } else {
                        throw new InvalidArgumentException('getResponse(): Bundle "LiipImagineBundle" does not exist or it is not enabled.');
                    }
                }
            }

            $output['data'][] = $item;
        }

        $outputHeader = array(
            'draw' => (int) $this->requestParams['draw'],
            'recordsTotal' => (int) $this->getCountAllResults($this->rootEntityIdentifier),
            'recordsFiltered' => (int) $this->getCountFilteredResults($this->rootEntityIdentifier),
        );

        $json = $this->serializer->serialize(array_merge($outputHeader, $output), 'json');
        $response = new Response($json);
        $response->headers->set('Content-Type', 'application/json');

        return $response;
    }

    //-------------------------------------------------
    // Helper
    //-------------------------------------------------

    /**
     * Add search/order columns.
     *
     * @param int    $key
     * @param string $columnTableName
     * @param string $data
     */
    private function addSearchOrderColumn($key, $columnTableName, $data)
    {
        $column = $this->columns[$key];

        true === $column->getOrderable() ? $this->orderColumns[] = $columnTableName.'.'.$data : $this->orderColumns[] = null;
        true === $column->getSearchable() ? $this->searchColumns[] = $columnTableName.'.'.$data : $this->searchColumns[] = null;
    }

    /**
     * Get metadata.
     *
     * @param string $entity
     *
     * @return ClassMetadata
     *
     * @throws \Exception
     */
    private function getMetadata($entity)
    {
        try {
            $metadata = $this->em->getMetadataFactory()->getMetadataFor($entity);
        } catch (MappingException $e) {
            throw new \Exception('getMetadata(): Given object '.$entity.' is not a Doctrine Entity.');
        }

        return $metadata;
    }

    /**
     * Get table name.
     *
     * @param ClassMetadata $metadata
     *
     * @return string
     */
    private function getTableName(ClassMetadata $metadata)
    {
        return strtolower($metadata->getTableName());
    }

    /**
     * Get identifier.
     *
     * @param ClassMetadata $metadata
     *
     * @return mixed
     */
    private function getIdentifier(ClassMetadata $metadata)
    {
        $identifiers = $metadata->getIdentifierFieldNames();

        return array_shift($identifiers);
    }

    /**
     * Set identifier from association.
     *
     * @param string|array       $association
     * @param string             $key
     * @param int                $i
     * @param ClassMetadata|null $metadata
     *
     * @return $this
     *
     * @throws \Exception
     */
    private function setIdentifierFromAssociation($association, $key = '', $i = 0, $metadata = null)
    {
        if (null === $metadata) {
            $metadata = $this->metadata;
        }

        if (is_string($association)) {
            $targetEntityClass = $metadata->getAssociationTargetClass($association);
            $targetMetadata = $this->getMetadata($targetEntityClass);
            $this->selectColumns[$association][] = $this->getIdentifier($targetMetadata);
        }

        if (is_array($association) && array_key_exists($i, $association)) {
            $column = $association[$i];
            $count = count($association) - 1;
            if (true === $metadata->hasAssociation($column)) {
                $targetEntityClass = $metadata->getAssociationTargetClass($column);
                $targetMetadata = $this->getMetadata($targetEntityClass);
                if ($count == $i) {
                    $this->selectColumns[$key][] = $this->getIdentifier($targetMetadata);
                } else {
                    ++$i;
                    $this->setIdentifierFromAssociation($association, $key, $i, $targetMetadata);
                }
            }
        }

        return $this;
    }

    /**
     * Is association.
     *
     * @param string $data
     *
     * @return bool|int
     */
    private function isAssociation($data)
    {
        return strpos($data, '.');
    }

    /**
     * Is select column.
     *
     * @param string $data
     *
     * @return bool
     */
    private function isSelectColumn($data)
    {
        if (null !== $data && !in_array($data, $this->virtualColumns)) {
            return true;
        }

        return false;
    }

    /**
     * Is search column.
     *
     * @param AbstractColumn $column
     *
     * @return bool
     */
    private function isSearchColumn(AbstractColumn $column)
    {
        if (false === $this->configs['search_on_non_visible_columns']) {
            if (null !== $column->getDql() && true === $column->getSearchable() && true === $column->getVisible()) {
                return true;
            }
        } else {
            if (null !== $column->getDql() && true === $column->getSearchable()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render image.
     *
     * @param string      $imageName
     * @param ImageColumn $column
     *
     * @return string
     */
    private function renderImage($imageName, ImageColumn $column)
    {
        return $this->twig->render(
            'SgDatatablesBundle:Helper:ii_render_image.html.twig',
            array(
                'image_id' => 'sg_image_'.uniqid(rand(10000, 99999)),
                'image_name' => $imageName,
                'filter' => $column->getImagineFilter(),
                'path' => $column->getRelativePath(),
                'holder_url' => $column->getHolderUrl(),
                'width' => $column->getHolderWidth(),
                'height' => $column->getHolderHeight(),
                'enlarge' => $column->getEnlarge(),
            )
        );
    }
}
