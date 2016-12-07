<?php
/**
 * SwiftOtter_Base is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * SwiftOtter_Base is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License
 * along with SwiftOtter_Base. If not, see <http://www.gnu.org/licenses/>.
 *
 * @author Joseph Maxwell
 * @copyright SwiftOtter Studios, 12/3/16
 * @package default
 **/

namespace Driver\Engines\MySql\Sandbox;

use Driver\Commands\CommandInterface;
use Driver\Pipeline\Transport\Status;
use Driver\Pipeline\Transport\TransportInterface;
use Driver\System\Configuration;
use Driver\System\Random;
use Symfony\Component\Console\Command\Command;

class Export extends Command implements CommandInterface
{
    private $connection;
    private $ssl;
    private $random;
    private $filename;
    private $configuration;

    public function __construct(Connection $connection, Ssl $ssl, Random $random, Configuration $configuration)
    {
        $this->connection = $connection;
        $this->ssl = $ssl;
        $this->random = $random;
        $this->configuration = $configuration;

        return parent::__construct('mysql-sandbox-export');
    }

    public function go(TransportInterface $transport)
    {
        $this->connection->test(function(Connection $connection) {
            $connection->authorizeIp();
        });

        if ($results = system($this->assembleCommand())) {
            throw new \Exception('Import to RDS instance failed: ' . $results);
        } else {
            return $transport
                ->withNewData('completed_file', $this->getFilename())
                ->withStatus(new Status('sandbox_init', 'success'));
        }
    }

    private function assembleCommand()
    {
        $command = implode(' ', [
            "mysqldump --user={$this->connection->getUser()}",
            "--password={$this->connection->getPassword()}",
            "--host={$this->connection->getHost()}",
            "--port={$this->connection->getPort()}",
            "--ssl-mode=VERIFY_CA",
            "--ssl-ca={$this->ssl->getPath()}",
            "{$this->connection->getDatabase()}"
        ]);

        if ($this->compressOutput()) {
            $command .= implode(' ', [
                '|',
                'gzip --best'
            ]);
        }

        $command .= implode(' ', [
            '>',
            $this->getFilename()
        ]);

        return $command;
    }

    private function compressOutput()
    {
        return (bool)$this->configuration->getNode('configuration/compress-output') === true;
    }

    private function getFilename()
    {
        if (!$this->filename) {
            do {
                $file = '/tmp/driver_tmp_' . $this->random->getRandomString(10) . ($this->compressOutput() ? '.gz' : '.sql');
            } while (file_exists($file));

            $this->filename = $file;
        }

        return $this->filename;
    }
}