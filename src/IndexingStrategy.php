<?php
/**
 * This file is part of the prooph/event-store-mysql-adapter.
 * (c) 2016-2016 prooph software GmbH <contact@prooph.de>
 * (c) 2016-2016 Sascha-Oliver Prolic <saschaprolic@googlemail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Prooph\EventStore\Adapter\MySQL;

interface IndexingStrategy
{
    public function __invoke(string $tableName): string;
}
