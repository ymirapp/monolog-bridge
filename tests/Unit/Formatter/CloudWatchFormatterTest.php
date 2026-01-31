<?php

declare(strict_types=1);

/*
 * This file is part of Ymir Monolog Bridge.
 *
 * (c) Carl Alexander <support@ymirapp.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Ymir\Bridge\Monolog\Tests\Unit\Formatter;

use Monolog\Level;
use Monolog\LogRecord;
use PHPUnit\Framework\TestCase;
use Ymir\Bridge\Monolog\Formatter\CloudWatchFormatter;

class CloudWatchFormatterTest extends TestCase
{
    public function testFormatBatch(): void
    {
        $formatter = new CloudWatchFormatter();

        $records = [
            [
                'message' => 'message 1',
                'level' => 200,
                'level_name' => 'INFO',
                'context' => [],
                'extra' => [],
                'channel' => 'test',
                'datetime' => new \DateTimeImmutable(),
            ],
            [
                'message' => 'message 2',
                'level' => 400,
                'level_name' => 'ERROR',
                'context' => [],
                'extra' => [],
                'channel' => 'test',
                'datetime' => new \DateTimeImmutable(),
            ],
        ];

        $output = $formatter->formatBatch($records);
        $this->assertStringContainsString("INFO\tmessage 1\t", $output);
        $this->assertStringContainsString("ERROR\tmessage 2\t", $output);
        $this->assertEquals(2, substr_count($output, "\n"));
    }

    public function testFormatWithArray(): void
    {
        $formatter = new CloudWatchFormatter();

        $record = [
            'message' => 'test message',
            'level' => 200,
            'level_name' => 'INFO',
            'context' => ['foo' => 'bar'],
            'extra' => ['baz' => 'qux'],
            'channel' => 'test',
            'datetime' => new \DateTimeImmutable(),
        ];

        $output = $formatter->format($record);

        $expectedLevel = 'INFO';
        $expectedMessage = 'test message';
        $this->assertStringStartsWith("$expectedLevel\t$expectedMessage\t", $output);

        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertEquals('test message', $data['message']);
        $this->assertEquals('INFO', $data['level']);
        $this->assertEquals(['foo' => 'bar'], $data['context']);
        $this->assertEquals(['baz' => 'qux'], $data['extra']);
    }

    public function testFormatWithException(): void
    {
        $formatter = new CloudWatchFormatter();

        $exception = new \RuntimeException('test exception', 123);
        $record = [
            'message' => 'error message',
            'level' => 500,
            'level_name' => 'CRITICAL',
            'context' => ['exception' => $exception],
            'extra' => [],
            'channel' => 'test',
            'datetime' => new \DateTimeImmutable(),
        ];

        $output = $formatter->format($record);
        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertArrayHasKey('exception', $data);
        $this->assertEquals('RuntimeException', $data['exception']['class']);
        $this->assertEquals('test exception', $data['exception']['message']);
        $this->assertEquals(123, $data['exception']['code']);
        $this->assertArrayNotHasKey('exception', $data['context'] ?? []);
    }

    public function testFormatWithLogRecord(): void
    {
        if (!class_exists(LogRecord::class)) {
            $this->markTestSkipped('Monolog 3 required');
        }

        $formatter = new CloudWatchFormatter();

        $record = new LogRecord(
            new \DateTimeImmutable(),
            'test',
            Level::Info,
            'test message',
            ['foo' => 'bar'],
            ['baz' => 'qux']
        );

        $output = $formatter->format($record);

        $this->assertStringStartsWith("INFO\ttest message\t", $output);
        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertEquals('test message', $data['message']);
        $this->assertEquals('INFO', $data['level']);
        $this->assertEquals(['foo' => 'bar'], $data['context']);
        $this->assertEquals(['baz' => 'qux'], $data['extra']);
    }

    public function testFormatWithNewLinesInMessage(): void
    {
        $formatter = new CloudWatchFormatter();

        $record = [
            'message' => "line 1\nline 2\r\nline 3",
            'level' => 200,
            'level_name' => 'INFO',
            'context' => [],
            'extra' => [],
            'channel' => 'test',
            'datetime' => new \DateTimeImmutable(),
        ];

        $output = $formatter->format($record);
        $this->assertStringContainsString("INFO\tline 1 line 2 line 3\t", $output);
    }

    public function testNormalizationOfDeepNesting(): void
    {
        $formatter = new CloudWatchFormatter();
        $formatter->setMaxNormalizeDepth(1);

        $record = [
            'message' => 'test',
            'level' => 200,
            'level_name' => 'INFO',
            'context' => [
                'level1' => [
                    'level2' => 'too deep',
                ],
            ],
        ];

        $output = $formatter->format($record);
        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertEquals(['level2' => 'Over 1 levels deep, aborting normalization'], $data['context']['level1']);
    }

    public function testNormalizationOfIncompleteClass(): void
    {
        $serialized = "O:17:\"Monolog\TestClass\":1:{s:23:\"\x00Monolog\TestClass\x00name\";s:4:\"test\";}";
        $object = unserialize($serialized);

        $formatter = new CloudWatchFormatter();
        $record = [
            'message' => 'test',
            'level' => 200,
            'level_name' => 'INFO',
            'context' => ['object' => $object],
        ];

        $output = @$formatter->format($record);
        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertEquals(['__PHP_Incomplete_Class' => 'Monolog\TestClass'], $data['context']['object']);
    }

    public function testNormalizationOfLargeArrays(): void
    {
        $formatter = new CloudWatchFormatter();
        $formatter->setMaxNormalizeItemCount(3);

        $record = [
            'message' => 'test',
            'level' => 200,
            'level_name' => 'INFO',
            'context' => [
                'large' => [1, 2, 3, 4],
            ],
        ];

        $output = $formatter->format($record);
        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertCount(4, $data['context']['large']);
        $this->assertEquals('Over 3 items (4 total), aborting normalization', $data['context']['large']['...']);
    }

    public function testNormalizationOfObjects(): void
    {
        $formatter = new CloudWatchFormatter();

        $foo = new class() {
            public $foo = 'fooValue';
        };

        $bar = new class() {
            public function __toString()
            {
                return 'bar';
            }
        };

        $record = [
            'message' => 'test',
            'level' => 200,
            'level_name' => 'INFO',
            'context' => [
                'date' => new \DateTimeImmutable('2023-01-01 00:00:00+0000'),
                'foo' => $foo,
                'bar' => $bar,
            ],
        ];

        $output = $formatter->format($record);
        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertEquals('2023-01-01T00:00:00+00:00', $data['context']['date']);
        $this->assertEquals(['foo' => 'fooValue'], $data['context']['foo']);
        $this->assertEquals('bar', $data['context']['bar']);
    }

    public function testNormalizationOfObjectWithFailingToString(): void
    {
        if (\PHP_VERSION_ID < 70400) {
            $this->markTestSkipped('PHP < 7.4 does not support throwing exceptions from __toString()');
        }

        $formatter = new CloudWatchFormatter();

        $object = new class() {
            public function __toString()
            {
                throw new \RuntimeException('Could not convert to string');
            }
        };

        $record = [
            'message' => 'test',
            'level' => 200,
            'level_name' => 'INFO',
            'context' => ['object' => $object],
        ];

        $output = $formatter->format($record);
        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertEquals([], $data['context']['object']);
    }

    public function testNormalizationOfRecursionInArrays(): void
    {
        $formatter = new CloudWatchFormatter();
        $formatter->setMaxNormalizeDepth(1);

        $array = [];
        $array['a'] = &$array;

        $record = [
            'message' => 'test',
            'level' => 200,
            'level_name' => 'INFO',
            'context' => ['recursive' => $array],
        ];

        $output = $formatter->format($record);
        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertEquals(['a' => 'Over 1 levels deep, aborting normalization'], $data['context']['recursive']);
    }

    public function testNormalizationOfResources(): void
    {
        $formatter = new CloudWatchFormatter();
        $res = fopen('php://memory', 'r');
        $record = [
            'message' => 'test',
            'level' => 200,
            'level_name' => 'INFO',
            'context' => ['res' => $res],
        ];

        $output = $formatter->format($record);
        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertEquals('[resource(stream)]', $data['context']['res']);
        fclose($res);
    }

    public function testNormalizationOfScalars(): void
    {
        $formatter = new CloudWatchFormatter();
        $record = [
            'message' => 'test',
            'level' => 200,
            'level_name' => 'INFO',
            'context' => [
                'inf' => INF,
                '-inf' => -INF,
                'nan' => acos(4),
                'float' => 1.5,
                'int' => 1,
                'bool' => true,
                'null' => null,
            ],
        ];

        $output = $formatter->format($record);
        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertEquals('INF', $data['context']['inf']);
        $this->assertEquals('-INF', $data['context']['-inf']);
        $this->assertEquals('NaN', $data['context']['nan']);
        $this->assertEquals(1.5, $data['context']['float']);
        $this->assertEquals(1, $data['context']['int']);
        $this->assertTrue($data['context']['bool']);
        $this->assertNull($data['context']['null']);
    }

    public function testNormalizationOfSoapFault(): void
    {
        if (!class_exists('SoapFault')) {
            $this->markTestSkipped('Soap extension required');
        }

        $formatter = new CloudWatchFormatter();
        $fault = new \SoapFault('foo', 'bar', 'actor', 'detail');
        $record = [
            'message' => 'test',
            'level' => 500,
            'level_name' => 'ERROR',
            'context' => ['exception' => $fault],
        ];

        $output = $formatter->format($record);
        $json = trim(substr($output, strrpos($output, "\t") + 1));
        $data = json_decode($json, true);

        $this->assertEquals('SoapFault', $data['exception']['class']);
        $this->assertEquals('foo', $data['exception']['faultcode']);
        $this->assertEquals('actor', $data['exception']['faultactor']);
        $this->assertEquals('detail', $data['exception']['detail']);
    }
}
