<?php
/**
 * @copyright 2018, Denis Mosolov <denismosolov@gmail.com>
 *
 * @author Denis Mosolov <denismosolov@gmail.com>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Afferoq General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace OCA\Talk\Tests\php\Command\Turn;

use OCA\Talk\Command\Turn\Add;
use OCP\IConfig;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Test\TestCase;

class AddTest extends TestCase {

	/** @var IConfig|\PHPUnit_Framework_MockObject_MockObject */
	private $config;

	/** @var Add|\PHPUnit_Framework_MockObject_MockObject */
	private $command;

	/** @var InputInterface|\PHPUnit_Framework_MockObject_MockObject */
	private $input;

	/** @var OutputInterface|\PHPUnit_Framework_MockObject_MockObject */
	private $output;

	public function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);

		$this->command = new Add($this->config);

		$this->input = $this->createMock(InputInterface::class);
		$this->output = $this->createMock(OutputInterface::class);
	}

	public function testServerEmptyString() {
		$this->input->method('getArgument')
			->willReturnCallback(function ($arg) {
				if ($arg === 'server') {
					return '';
				} elseif ($arg === 'protocols') {
					return 'udp,tcp';
				}
				throw new \Exception();
			});
		$this->input->method('getOption')
			->willReturnCallback(function ($arg) {
				if ($arg === 'secret') {
					return 'my-test-secret';
				} elseif ($arg === 'generate-secret') {
					return false;
				}
				throw new \Exception();
			});
		$this->output->expects($this->once())
			->method('writeln')
			->with($this->equalTo('<error>Server cannot be empty.</error>'));
		$this->config->expects($this->never())
			->method('setAppValue');

		$this->invokePrivate($this->command, 'execute', [$this->input, $this->output]);
	}

	public function testSecretEmpty() {
		$this->input->method('getArgument')
			->willReturnCallback(function ($arg) {
				if ($arg === 'server') {
					return 'turn.test.com';
				} elseif ($arg === 'protocols') {
					return 'udp,tcp';
				}
				throw new \Exception();
			});
		$this->input->method('getOption')
			->willReturnCallback(function ($arg) {
				if ($arg === 'secret') {
					return '';
				} elseif ($arg === 'generate-secret') {
					return false;
				}
				throw new \Exception();
			});
		$this->output->expects($this->once())
			->method('writeln')
			->with($this->equalTo('<error>Secret cannot be empty.</error>'));
		$this->config->expects($this->never())
			->method('setAppValue');

		$this->invokePrivate($this->command, 'execute', [$this->input, $this->output]);
	}

	public function testGenerateSecret() {
		$this->input->method('getArgument')
			->willReturnCallback(function ($arg) {
				if ($arg === 'server') {
					return 'turn.test.com';
				} elseif ($arg === 'protocols') {
					return 'udp,tcp';
				}
				throw new \Exception();
			});
		$this->input->method('getOption')
			->willReturnCallback(function ($arg) {
				if ($arg === 'secret') {
					return null;
				} elseif ($arg === 'generate-secret') {
					return true;
				}
				throw new \Exception();
			});

		$command = $this->getMockBuilder(Add::class)
			->onlyMethods(['getUniqueSecret'])
			->setConstructorArgs([$this->config])
			->getMock();
		$command->expects($this->once())
			->method('getUniqueSecret')
			->willReturn('my-generaeted-test-secret');
		$this->config->method('getAppValue')
			->with('spreed', 'turn_servers')
			->willReturn(json_encode([]));
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(
				$this->equalTo('spreed'),
				$this->equalTo('turn_servers'),
				$this->equalTo(json_encode([
					[
						'server' => 'turn.test.com',
						'secret' => 'my-generaeted-test-secret',
						'protocols' => 'udp,tcp'
					]
				]))
			);
		$this->output->expects($this->once())
			->method('writeln')
			->with($this->equalTo('<info>Added turn.test.com.</info>'));

		$this->invokePrivate($command, 'execute', [$this->input, $this->output]);
	}

	public function testSecretAndGenerateSecretOptions() {
		$this->input->method('getArgument')
			->willReturnCallback(function ($arg) {
				if ($arg === 'server') {
					return 'turn.test.com';
				} elseif ($arg === 'protocols') {
					return 'udp,tcp';
				}
				throw new \Exception();
			});
		$this->input->method('getOption')
			->willReturnCallback(function ($arg) {
				if ($arg === 'secret') {
					return 'my-test-secret';
				} elseif ($arg === 'generate-secret') {
					return true;
				}
				throw new \Exception();
			});
		$this->output->expects($this->once())
			->method('writeln')
			->with($this->equalTo('<error>You must provide --secret or --generate-secret.</error>'));
		$this->config->expects($this->never())
			->method('setAppValue');

		$this->invokePrivate($this->command, 'execute', [$this->input, $this->output]);
	}

	public function testInvalidProtocolsString() {
		$this->input->method('getArgument')
			->willReturnCallback(function ($arg) {
				if ($arg === 'server') {
					return 'turn.test.com';
				} elseif ($arg === 'protocols') {
					return 'invalid-protocol';
				}
				throw new \Exception();
			});
		$this->input->method('getOption')
			->willReturnCallback(function ($arg) {
				if ($arg === 'secret') {
					return 'my-test-secret';
				} elseif ($arg === 'generate-secret') {
					return false;
				}
				throw new \Exception();
			});
		$this->output->expects($this->once())
			->method('writeln')
			->with($this->equalTo('<error>Not allowed protocols, must be udp or tcp or udp,tcp.</error>'));
		$this->config->expects($this->never())
			->method('setAppValue');

		$this->invokePrivate($this->command, 'execute', [$this->input, $this->output]);
	}

	public function testAddServerToEmptyList() {
		$this->input->method('getArgument')
			->willReturnCallback(function ($arg) {
				if ($arg === 'server') {
					return 'turn.test.com';
				} elseif ($arg === 'protocols') {
					return 'udp,tcp';
				}
				throw new \Exception();
			});
		$this->input->method('getOption')
			->willReturnCallback(function ($arg) {
				if ($arg === 'secret') {
					return 'my-test-secret';
				} elseif ($arg === 'generate-secret') {
					return false;
				}
				throw new \Exception();
			});
		$this->config->method('getAppValue')
			->with('spreed', 'turn_servers')
			->willReturn(json_encode([]));
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(
				$this->equalTo('spreed'),
				$this->equalTo('turn_servers'),
				$this->equalTo(json_encode([
					[
						'server' => 'turn.test.com',
						'secret' => 'my-test-secret',
						'protocols' => 'udp,tcp'
					]
				]))
			);
		$this->output->expects($this->once())
			->method('writeln')
			->with($this->equalTo('<info>Added turn.test.com.</info>'));

		$this->invokePrivate($this->command, 'execute', [$this->input, $this->output]);
	}

	public function testAddServerToNonEmptyList() {
		$this->input->method('getArgument')
			->willReturnCallback(function ($arg) {
				if ($arg === 'server') {
					return 'turn2.test.com';
				} elseif ($arg === 'protocols') {
					return 'udp,tcp';
				}
				throw new \Exception();
			});
		$this->input->method('getOption')
			->willReturnCallback(function ($arg) {
				if ($arg === 'secret') {
					return 'my-test-secret-2';
				} elseif ($arg === 'generate-secret') {
					return false;
				}
				throw new \Exception();
			});
		$this->config->method('getAppValue')
			->with('spreed', 'turn_servers')
			->willReturn(json_encode([
				[
					'server' => 'turn1.test.com',
					'secret' => 'my-test-secret-1',
					'protocols' => 'udp,tcp'
				]
			]));
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(
				$this->equalTo('spreed'),
				$this->equalTo('turn_servers'),
				$this->equalTo(json_encode([
					[
						'server' => 'turn1.test.com',
						'secret' => 'my-test-secret-1',
						'protocols' => 'udp,tcp'
					],
					[
						'server' => 'turn2.test.com',
						'secret' => 'my-test-secret-2',
						'protocols' => 'udp,tcp'
					]
				]))
			);
		$this->output->expects($this->once())
			->method('writeln')
			->with($this->equalTo('<info>Added turn2.test.com.</info>'));

		$this->invokePrivate($this->command, 'execute', [$this->input, $this->output]);
	}

	public function testServerSanitization() {
		$this->input->method('getArgument')
			->willReturnCallback(function ($arg) {
				if ($arg === 'server') {
					return 'https://turn.test.com';
				} elseif ($arg === 'protocols') {
					return 'udp,tcp';
				}
				throw new \Exception();
			});
		$this->input->method('getOption')
			->willReturnCallback(function ($arg) {
				if ($arg === 'secret') {
					return 'my-test-secret';
				} elseif ($arg === 'generate-secret') {
					return false;
				}
				throw new \Exception();
			});
		$this->config->method('getAppValue')
			->with('spreed', 'turn_servers')
			->willReturn(json_encode([]));
		$this->config->expects($this->once())
			->method('setAppValue')
			->with(
				$this->equalTo('spreed'),
				$this->equalTo('turn_servers'),
				$this->equalTo(json_encode([
					[
						'server' => 'turn.test.com',
						'secret' => 'my-test-secret',
						'protocols' => 'udp,tcp'
					]
				]))
			);

		$this->invokePrivate($this->command, 'execute', [$this->input, $this->output]);
	}
}
