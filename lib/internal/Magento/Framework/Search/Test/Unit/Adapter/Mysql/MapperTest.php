<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Framework\Search\Test\Unit\Adapter\Mysql;

use \Magento\Framework\Search\Adapter\Mysql\Mapper;

use Magento\Framework\Search\Adapter\Mysql\Query\Builder\Match;
use Magento\Framework\Search\Adapter\Mysql\TemporaryStorage;
use PHPUnit_Framework_MockObject_MockObject as MockObject;
use Magento\Framework\App\Resource;
use Magento\Framework\Search\Request\Query\BoolExpression;
use Magento\Framework\Search\Request\Query\Filter;
use Magento\Framework\Search\Request\QueryInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class MapperTest extends \PHPUnit_Framework_TestCase
{
    const INDEX_NAME = 'test_index_fulltext';
    const REQUEST_LIMIT = 120321;

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\IndexBuilderInterface|MockObject
     */
    private $indexBuilder;

    /**
     * @var TemporaryStorage|MockObject
     */
    private $temporaryStorage;

    /**
     * @var Match|MockObject
     */
    private $matchBuilder;

    /**
     * @var \Magento\Framework\Search\RequestInterface|MockObject
     */
    private $request;

    /**
     * @var \Magento\Framework\DB\Select|MockObject
     */
    private $select;

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\ScoreBuilder|MockObject
     */
    private $scoreBuilder;

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\ScoreBuilderFactory|MockObject
     */
    private $scoreBuilderFactory;

    /**
     * @var \Magento\Framework\App\Resource|MockObject
     */
    private $resource;

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\Query\Builder\Match|MockObject
     */
    private $queryContainer;

    /**
     * @var \Magento\Framework\Search\Adapter\Mysql\Filter\Builder|MockObject
     */
    private $filterBuilder;

    /**
     * @var Mapper
     */
    private $mapper;

    protected function setUp()
    {
        $helper = new ObjectManager($this);

        $this->select = $this->getMockBuilder('Magento\Framework\DB\Select')
            ->setMethods(['group', 'limit', 'where', 'columns', 'from', 'join', 'joinInner', 'order'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->select->expects($this->any())
            ->method('from')
            ->willReturnSelf();

        $connectionAdapter = $this->getMockBuilder('Magento\Framework\DB\Adapter\AdapterInterface')
            ->setMethods(['select'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $connectionAdapter->expects($this->any())->method('select')->will($this->returnValue($this->select));

        $this->resource = $this->getMockBuilder('Magento\Framework\App\Resource')
            ->disableOriginalConstructor()
            ->getMock();
        $this->resource->expects($this->any())->method('getConnection')
            ->will($this->returnValue($connectionAdapter));

        $this->mockBuilders();

        $this->scoreBuilderFactory = $this->getMockBuilder('Magento\Framework\Search\Adapter\Mysql\ScoreBuilderFactory')
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->scoreBuilderFactory->expects($this->any())->method('create')
            ->will($this->returnValue($this->scoreBuilder));

        $this->request = $this->getMockBuilder('Magento\Framework\Search\RequestInterface')
            ->setMethods(['getQuery', 'getIndex', 'getSize'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->queryContainer = $this->getMockBuilder('Magento\Framework\Search\Adapter\Mysql\Query\QueryContainer')
            ->setMethods(['addMatchQuery', 'getMatchQueries', 'getFilterQueries'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->queryContainer->expects($this->any())
            ->method('addMatchQuery')
            ->willReturnArgument(0);
        $queryContainerFactory = $this->getMockBuilder(
            'Magento\Framework\Search\Adapter\Mysql\Query\QueryContainerFactory'
        )
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $queryContainerFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->queryContainer);

        $this->temporaryStorage = $this->getMockBuilder('\Magento\Framework\Search\Adapter\Mysql\TemporaryStorage')
            ->setMethods(['storeDocumentsFromSelect'])
            ->disableOriginalConstructor()
            ->getMock();

        $this->request->expects($this->exactly(2))
            ->method('getIndex')
            ->will($this->returnValue(self::INDEX_NAME));

        $temporaryStorageFactory = $this->getMockBuilder(
            '\Magento\Framework\Search\Adapter\Mysql\TemporaryStorageFactory'
        )
            ->setMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $temporaryStorageFactory->expects($this->any())
            ->method('create')
            ->willReturn($this->temporaryStorage);

        $this->mapper = $helper->getObject(
            'Magento\Framework\Search\Adapter\Mysql\Mapper',
            [
                'resource' => $this->resource,
                'scoreBuilderFactory' => $this->scoreBuilderFactory,
                'queryContainerFactory' => $queryContainerFactory,
                'filterBuilder' => $this->filterBuilder,
                'matchBuilder' => $this->matchBuilder,
                'indexProviders' => [self::INDEX_NAME => $this->indexBuilder],
                'temporaryStorageFactory' => $temporaryStorageFactory
            ]
        );
    }

    public function testBuildMatchQuery()
    {
        $query = $this->createMatchQuery();

        $this->select->expects($this->any())
            ->method('limit')
            ->with(self::REQUEST_LIMIT)
            ->willReturnSelf();
        $this->select->expects($this->once())
            ->method('order')
            ->with('relevance DESC')
            ->willReturnSelf();
        $this->request->expects($this->once())
            ->method('getSize')
            ->willReturn(self::REQUEST_LIMIT);

        $this->queryContainer->expects($this->once())
            ->method('getMatchQueries')
            ->willReturn([]);

        $this->queryContainer->expects($this->any())->method('addMatchQuery')
            ->with(
                $this->equalTo($this->select),
                $this->equalTo($query),
                $this->equalTo(BoolExpression::QUERY_CONDITION_MUST)
            )
            ->will($this->returnValue($this->select));

        $this->request->expects($this->once())->method('getQuery')->will($this->returnValue($query));

        $this->select->expects($this->any())->method('columns')->will($this->returnValue($this->select));

        $response = $this->mapper->buildQuery($this->request);

        $this->assertEquals($this->select, $response);
    }

    public function testBuildFilterQuery()
    {
        $query = $this->createFilterQuery(Filter::REFERENCE_FILTER, $this->createFilter());

        $this->select->expects($this->any())
            ->method('limit')
            ->with(self::REQUEST_LIMIT)
            ->willReturnSelf();
        $this->select->expects($this->once())
            ->method('order')
            ->with('relevance DESC')
            ->willReturnSelf();
        $this->request->expects($this->once())
            ->method('getSize')
            ->willReturn(self::REQUEST_LIMIT);

        $this->select->expects($this->any())->method('columns')->will($this->returnValue($this->select));

        $this->request->expects($this->once())->method('getQuery')->will($this->returnValue($query));

        $this->filterBuilder->expects($this->once())->method('build')->will($this->returnValue('(1)'));

        $response = $this->mapper->buildQuery($this->request);

        $this->assertEquals($this->select, $response);
    }

    /**
     * @param $query
     * @param array $derivedQueries
     * @throws \Exception
     * @dataProvider buildQueryDataProvider
     */
    public function testBuildQuery($query, array $derivedQueries = [])
    {
        $this->select->expects($this->any())
            ->method('limit')
            ->with(self::REQUEST_LIMIT)
            ->willReturnSelf();
        $this->select->expects($this->once())
            ->method('order')
            ->with('relevance DESC')
            ->willReturnSelf();
        $this->request->expects($this->once())
            ->method('getSize')
            ->willReturn(self::REQUEST_LIMIT);

        $this->filterBuilder->expects($this->any())->method('build')->will($this->returnValue('(1)'));

        $table = $this->getMockBuilder('\Magento\Framework\DB\Ddl\Table')
            ->disableOriginalConstructor()
            ->getMock();
        $this->temporaryStorage->expects($this->any())
            ->method('storeDocumentsFromSelect')
            ->willReturn($table);
        $table->expects($this->any())
            ->method('getName')
            ->willReturn('table_name');

        $this->queryContainer->expects($this->any())
            ->method('getMatchQueries')
            ->willReturn($derivedQueries);

        $this->select->expects($this->any())->method('columns')->will($this->returnValue($this->select));

        $this->request->expects($this->once())->method('getQuery')->will($this->returnValue($query));

        $response = $this->mapper->buildQuery($this->request);

        $this->assertEquals($this->select, $response);
    }

    /**
     * @return array
     */
    public function buildQueryDataProvider()
    {
        return [
            [
                $this->createBoolQuery(
                    [
                        $this->createMatchQuery(),
                        $this->createFilterQuery(Filter::REFERENCE_QUERY, $this->createMatchQuery()),
                    ],
                    [
                        $this->createMatchQuery(),
                        $this->createFilterQuery(Filter::REFERENCE_FILTER, $this->createFilter()),
                    ],
                    [
                        $this->createMatchQuery(),
                        $this->createFilterQuery(Filter::REFERENCE_FILTER, $this->createFilter()),
                    ]
                ),
            ],
            [
                $this->createBoolQuery(
                    [
                        $this->createMatchQuery(),
                        $this->createMatchQuery(),
                    ],
                    [],
                    []
                ),
                [
                    $this->createMatchContainer(
                        $this->createMatchQuery(),
                        'mustNot'
                    ),
                ],
            ],
            [
                $this->createBoolQuery(
                    [
                        $this->createMatchQuery(),
                        $this->createMatchQuery(),
                    ],
                    [],
                    []
                ),
                [
                    $this->createMatchContainer(
                        $this->createMatchQuery(),
                        'mustNot'
                    ),
                    $this->createMatchContainer(
                        $this->createMatchQuery(),
                        'must'
                    ),
                ],
            ],
        ];
    }

    /**
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessage Unknown query type 'unknownQuery'
     */
    public function testGetUnknownQueryType()
    {
        $query = $this->getMockBuilder('Magento\Framework\Search\Request\QueryInterface')
            ->setMethods(['getType'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $query->expects($this->exactly(2))
            ->method('getType')
            ->will($this->returnValue('unknownQuery'));

        $this->select->expects($this->never())
            ->method('limit');
        $this->select->expects($this->never())
            ->method('order');

        $this->request->expects($this->once())->method('getQuery')->will($this->returnValue($query));

        $this->mapper->buildQuery($this->request);
    }

    /**
     * @return MockObject|\Magento\Framework\Search\Request\Query\Match
     */
    private function createMatchQuery()
    {
        $query = $this->getMockBuilder('Magento\Framework\Search\Request\Query\Match')
            ->setMethods(['getType'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $query->expects($this->once())->method('getType')
            ->will($this->returnValue(QueryInterface::TYPE_MATCH));
        return $query;
    }

    /**
     * @param string $referenceType
     * @param mixed $reference
     * @return MockObject|\Magento\Framework\Search\Request\Query\Filter
     */
    private function createFilterQuery($referenceType, $reference)
    {
        $query = $this->getMockBuilder('Magento\Framework\Search\Request\Query\Filter')
            ->setMethods(['getType', 'getReferenceType', 'getReference'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $query->expects($this->exactly(1))
            ->method('getType')
            ->will($this->returnValue(QueryInterface::TYPE_FILTER));
        $query->expects($this->once())->method('getReferenceType')
            ->will($this->returnValue($referenceType));
        $query->expects($this->once())->method('getReference')
            ->will($this->returnValue($reference));
        return $query;
    }

    /**
     * @return MockObject|\Magento\Framework\Search\Request\Query\BoolExpression
     */
    private function createBoolQuery(array $must, array $should, array $mustNot)
    {
        $query = $this->getMockBuilder('Magento\Framework\Search\Request\Query\BoolExpression')
            ->setMethods(['getMust', 'getShould', 'getMustNot', 'getType'])
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
        $query->expects($this->exactly(1))
            ->method('getType')
            ->will($this->returnValue(QueryInterface::TYPE_BOOL));
        $query->expects($this->once())
            ->method('getMust')
            ->will($this->returnValue($must));
        $query->expects($this->once())
            ->method('getShould')
            ->will($this->returnValue($should));
        $query->expects($this->once())
            ->method('getMustNot')
            ->will($this->returnValue($mustNot));
        return $query;
    }

    /**
     * @return MockObject|\Magento\Framework\Search\Request\FilterInterface
     */
    private function createFilter()
    {
        return $this->getMockBuilder('Magento\Framework\Search\Request\FilterInterface')
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();
    }

    /**
     * @param $request
     * @param $conditionType
     * @return MockObject|\Magento\Framework\Search\Adapter\Mysql\Query\MatchContainer
     */
    private function createMatchContainer($request, $conditionType)
    {
        $matchContainer = $this->getMockBuilder('\Magento\Framework\Search\Adapter\Mysql\Query\MatchContainer')
            ->setMethods(['getRequest', 'getConditionType'])
            ->disableOriginalConstructor()
            ->getMock();
        $matchContainer->expects($this->any())
            ->method('getRequest')
            ->willReturn($request);
        $matchContainer->expects($this->any())
            ->method('getConditionType')
            ->willReturn($conditionType);
        return $matchContainer;
    }

    private function mockBuilders()
    {
        $this->scoreBuilder = $this->getMockBuilder('Magento\Framework\Search\Adapter\Mysql\ScoreBuilder')
            ->setMethods(['clear'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->filterBuilder = $this->getMockBuilder('Magento\Framework\Search\Adapter\Mysql\Filter\Builder')
            ->setMethods(['build'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->matchBuilder = $this->getMockBuilder('\Magento\Framework\Search\Adapter\Mysql\Query\Builder\Match')
            ->setMethods(['build'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->matchBuilder->expects($this->any())
            ->method('build')
            ->willReturnArgument(1);
        $this->indexBuilder = $this->getMockBuilder('\Magento\Framework\Search\Adapter\Mysql\IndexBuilderInterface')
            ->disableOriginalConstructor()
            ->setMethods(['build'])
            ->getMockForAbstractClass();
        $this->indexBuilder->expects($this->any())
            ->method('build')
            ->will($this->returnValue($this->select));
    }
}
