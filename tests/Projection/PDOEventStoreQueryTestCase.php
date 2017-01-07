<?php
/**
 * This file is part of the prooph/pdo-event-store.
 * (c) 2016-2016 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2016 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace ProophTest\EventStore\PDO\Projection;

use ArrayIterator;
use PDO;
use PHPUnit\Framework\TestCase;
use Prooph\Common\Messaging\Message;
use Prooph\EventStore\EventStore;
use Prooph\EventStore\Stream;
use Prooph\EventStore\StreamName;
use ProophTest\EventStore\Mock\UserCreated;
use ProophTest\EventStore\Mock\UsernameChanged;

abstract class PDOEventStoreQueryTestCase extends TestCase
{
    /**
     * @var EventStore
     */
    protected $eventStore;

    /**
     * @var PDO
     */
    protected $connection;

    protected function tearDown(): void
    {
        // these tables are used in every test case
        $this->connection->exec('DROP TABLE event_streams;');
        $this->connection->exec('DROP TABLE projections;');
        $this->connection->exec('DROP TABLE _' . sha1('user-123'));
        // these tables are used only in some test cases
        $this->connection->exec('DROP TABLE IF EXISTS _' . sha1('user-234'));
        $this->connection->exec('DROP TABLE IF EXISTS _' . sha1('$iternal-345'));
        $this->connection->exec('DROP TABLE IF EXISTS _' . sha1('guest-345'));
        $this->connection->exec('DROP TABLE IF EXISTS _' . sha1('guest-456'));
        $this->connection->exec('DROP TABLE IF EXISTS _' . sha1('foo'));
        $this->connection->exec('DROP TABLE IF EXISTS _' . sha1('test_projection'));
    }

    protected function prepareEventStream(string $name): void
    {
        $events = [];
        $events[] = UserCreated::with([
            'name' => 'Alex',
        ], 1);
        for ($i = 2; $i < 50; $i++) {
            $events[] = UsernameChanged::with([
                'name' => uniqid('name_'),
            ], $i);
        }
        $events[] = UsernameChanged::with([
            'name' => 'Sascha',
        ], 50);

        $this->eventStore->create(new Stream(new StreamName($name), new ArrayIterator($events)));
    }

    /**
     * @test
     */
    public function it_can_query_from_stream_and_reset()
    {
        $this->prepareEventStream('user-123');

        $query = $this->eventStore->createQuery();

        $query
            ->init(function (): array {
                return ['count' => 0];
            })
            ->fromStream('user-123')
            ->when([
                UsernameChanged::class => function (array $state, UsernameChanged $event): array {
                    $state['count']++;

                    return $state;
                },
            ])
            ->run();

        $this->assertEquals(49, $query->getState()['count']);

        $query->reset();

        $query->run();

        $this->assertEquals(49, $query->getState()['count']);
    }

    /**
     * @test
     */
    public function it_can_query_from_streams(): void
    {
        $this->prepareEventStream('user-123');
        $this->prepareEventStream('user-234');

        $query = $this->eventStore->createQuery();

        $query
            ->init(function (): array {
                return ['count' => 0];
            })
            ->fromStreams('user-123', 'user-234')
            ->whenAny(
                function (array $state, Message $event): array {
                    $state['count']++;

                    return $state;
                }
            )
            ->run();

        $this->assertEquals(100, $query->getState()['count']);
    }

    /**
     * @test
     */
    public function it_can_query_from_all_ignoring_internal_streams(): void
    {
        $this->prepareEventStream('user-123');
        $this->prepareEventStream('user-234');
        $this->prepareEventStream('$iternal-345');

        $query = $this->eventStore->createQuery();

        $query
            ->init(function (): array {
                return ['count' => 0];
            })
            ->fromAll()
            ->whenAny(
                function (array $state, Message $event): array {
                    $state['count']++;

                    return $state;
                }
            )
            ->run();

        $this->assertEquals(100, $query->getState()['count']);
    }

    /**
     * @test
     */
    public function it_can_query_from_category_with_when_all()
    {
        $this->prepareEventStream('user-123');
        $this->prepareEventStream('user-234');

        $query = $this->eventStore->createQuery();

        $query
            ->init(function (): array {
                return ['count' => 0];
            })
            ->fromCategory('user')
            ->whenAny(
                function (array $state, Message $event): array {
                    $state['count']++;

                    return $state;
                }
            )
            ->run();

        $this->assertEquals(100, $query->getState()['count']);
    }

    /**
     * @test
     */
    public function it_can_query_from_categories_with_when()
    {
        $this->prepareEventStream('user-123');
        $this->prepareEventStream('user-234');
        $this->prepareEventStream('guest-345');
        $this->prepareEventStream('guest-456');

        $query = $this->eventStore->createQuery();

        $query
            ->init(function (): array {
                return ['count' => 0];
            })
            ->fromCategories('user', 'guest')
            ->when([
                UserCreated::class => function (array $state, Message $event): array {
                    $state['count']++;

                    return $state;
                },
            ])
            ->run();

        $this->assertEquals(4, $query->getState()['count']);
    }

    public function it_resumes_query_from_position(): void
    {
        $this->prepareEventStream('user-123');

        $query = $this->eventStore->createQuery();

        $query
            ->init(function (): array {
                return ['count' => 0];
            })
            ->fromCategories('user', 'guest')
            ->when([
                UsernameChanged::class => function (array $state, Message $event): array {
                    $state['count']++;

                    return $state;
                },
            ])
            ->run();

        $this->assertEquals(49, $query->getState()['count']);

        $events = [];
        for ($i = 51; $i <= 100; $i++) {
            $events[] = UsernameChanged::with([
                'name' => uniqid('name_'),
            ], $i);
        }

        $this->eventStore->appendTo(new StreamName('user-123'), new ArrayIterator($events));

        $query->run();

        $this->assertEquals(99, $query->getState()['count']);
    }

    /**
     * @test
     */
    public function it_resets_to_empty_array(): void
    {
        $query = $this->eventStore->createQuery();

        $state = $query->getState();

        $this->assertInternalType('array', $state);

        $query->reset();

        $state2 = $query->getState();

        $this->assertInternalType('array', $state2);
    }

    /**
     * @test
     */
    public function it_can_be_stopped_while_processing()
    {
        $this->prepareEventStream('user-123');

        $query = $this->eventStore->createQuery();

        $query
            ->init(function (): array {
                return ['count' => 0];
            })
            ->fromStream('user-123')
            ->whenAny(function (array $state, Message $event): array {
                $state['count']++;

                if ($state['count'] === 10) {
                    $this->stop();
                }

                return $state;
            })
            ->run();

        $this->assertEquals(10, $query->getState()['count']);
    }
}
