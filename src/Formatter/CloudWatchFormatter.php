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

namespace Ymir\Bridge\Monolog\Formatter;

use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;
use Monolog\Utils;

/**
 * Monolog formatter optimized for CloudWatch logs.
 */
class CloudWatchFormatter implements FormatterInterface
{
    /**
     * The simple date format used by default.
     */
    public const SIMPLE_DATE = "Y-m-d\TH:i:sP";

    /**
     * The date format to use for timestamps.
     *
     * @var string
     */
    protected $dateFormat;

    /**
     * The JSON encode options.
     *
     * @var int
     */
    protected $jsonEncodeOptions = Utils::DEFAULT_JSON_FLAGS;

    /**
     * The maximum number of normalization levels to go through.
     *
     * @var int
     */
    protected $maxNormalizeDepth = 9;

    /**
     * The maximum number of items to normalize per level.
     *
     * @var int
     */
    protected $maxNormalizeItemCount = 1000;

    /**
     * Constructor.
     */
    public function __construct(?string $dateFormat = null)
    {
        $this->dateFormat = $dateFormat ?? static::SIMPLE_DATE;
    }

    /**
     * Add a JSON encode option.
     */
    public function addJsonEncodeOption(int $option): self
    {
        $this->jsonEncodeOptions |= $option;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function format($record): string
    {
        $level = 'UNKNOWN';
        $message = '';

        if ($record instanceof LogRecord) {
            $level = $record->level->getName();
            $message = $record->message;
        } elseif (is_array($record)) {
            $level = strtoupper((string) ($record['level_name'] ?? $record['level']['name'] ?? 'UNKNOWN'));
            $message = (string) ($record['message'] ?? '');
        }

        $message = str_replace(["\r\n", "\r", "\n"], ' ', $message);
        $json = $this->toJson($this->normalizeRecord($record), true);
        $line = sprintf("%s\t%s\t%s\n", $level, $message, $json);
        $requestId = $_SERVER['AWS_REQUEST_ID'] ?? null;

        if (!empty($requestId) && is_string($requestId)) {
            $line = "{$requestId}\t{$line}";
        }

        return $line;
    }

    /**
     * {@inheritdoc}
     */
    public function formatBatch(array $records): string
    {
        return implode('', array_map([$this, 'format'], $records));
    }

    /**
     * Get the date format.
     */
    public function getDateFormat(): string
    {
        return $this->dateFormat;
    }

    /**
     * Get the maximum normalization depth.
     */
    public function getMaxNormalizeDepth(): int
    {
        return $this->maxNormalizeDepth;
    }

    /**
     * Get the maximum normalization item count.
     */
    public function getMaxNormalizeItemCount(): int
    {
        return $this->maxNormalizeItemCount;
    }

    /**
     * Remove a JSON encode option.
     */
    public function removeJsonEncodeOption(int $option): self
    {
        $this->jsonEncodeOptions &= ~$option;

        return $this;
    }

    /**
     * Set the date format.
     */
    public function setDateFormat(string $dateFormat): self
    {
        $this->dateFormat = $dateFormat;

        return $this;
    }

    /**
     * Set the JSON encode options.
     */
    public function setJsonEncodeOptions(int $options): self
    {
        $this->jsonEncodeOptions = $options;

        return $this;
    }

    /**
     * Set if JSON should be pretty printed.
     */
    public function setJsonPrettyPrint(bool $enable): self
    {
        if ($enable) {
            $this->jsonEncodeOptions |= JSON_PRETTY_PRINT;
        } else {
            $this->jsonEncodeOptions &= ~JSON_PRETTY_PRINT;
        }

        return $this;
    }

    /**
     * Set the maximum normalization depth.
     */
    public function setMaxNormalizeDepth(int $maxNormalizeDepth): self
    {
        $this->maxNormalizeDepth = $maxNormalizeDepth;

        return $this;
    }

    /**
     * Set the maximum normalization item count.
     */
    public function setMaxNormalizeItemCount(int $maxNormalizeItemCount): self
    {
        $this->maxNormalizeItemCount = $maxNormalizeItemCount;

        return $this;
    }

    /**
     * Formats a date.
     */
    private function formatDate(\DateTimeInterface $date): string
    {
        return $date->format($this->dateFormat);
    }

    /**
     * Normalize an arbitrary value to a scalar|array|null.
     */
    private function normalize($data, int $depth = 0)
    {
        if ($depth > $this->maxNormalizeDepth) {
            return sprintf('Over %s levels deep, aborting normalization', $this->maxNormalizeDepth);
        }

        if (null === $data || is_scalar($data)) {
            return $this->normalizeScalar($data);
        }

        if (is_array($data)) {
            return $this->normalizeArray($data, $depth);
        }

        if (is_object($data)) {
            return $this->normalizeObject($data, $depth);
        }

        if (is_resource($data)) {
            return sprintf('[resource(%s)]', get_resource_type($data));
        }

        return '[unknown('.gettype($data).')]';
    }

    /**
     * Normalizes an array.
     */
    private function normalizeArray(array $data, int $depth): array
    {
        $count = 1;
        $normalized = [];

        foreach ($data as $key => $value) {
            if ($count++ > $this->maxNormalizeItemCount) {
                $normalized['...'] = sprintf('Over %s items (%s total), aborting normalization', $this->maxNormalizeItemCount, count($data));

                break;
            }

            $normalized[$key] = $this->normalize($value, $depth + 1);
        }

        return $normalized;
    }

    /**
     * Normalizes an exception.
     */
    private function normalizeException(\Throwable $exception, int $depth = 0): array
    {
        if ($depth > $this->maxNormalizeDepth) {
            return [sprintf('Over %s levels deep, aborting normalization', $this->maxNormalizeDepth)];
        }

        if ($exception instanceof \JsonSerializable) {
            return (array) $exception->jsonSerialize();
        }

        $data = [
            'class' => Utils::getClass($exception),
            'message' => $exception->getMessage(),
            'code' => (int) $exception->getCode(),
            'file' => $exception->getFile().':'.$exception->getLine(),
        ];

        if ($exception instanceof \SoapFault) {
            $data = array_merge($data, $this->normalizeSoapFault($exception));
        }

        $trace = $this->normalizeExceptionTrace($exception);

        if ([] !== $trace) {
            $data['trace'] = $trace;
        }

        if ($previous = $exception->getPrevious()) {
            $data['previous'] = $this->normalizeException($previous, $depth + 1);
        }

        return $data;
    }

    /**
     * Normalizes an exception trace.
     */
    private function normalizeExceptionTrace(\Throwable $exception): array
    {
        $trace = [];

        foreach ($exception->getTrace() as $frame) {
            if (isset($frame['file'], $frame['line'])) {
                $trace[] = $frame['file'].':'.$frame['line'];
            }
        }

        return $trace;
    }

    /**
     * Normalizes an object.
     */
    private function normalizeObject($data, int $depth)
    {
        if ($data instanceof \DateTimeInterface) {
            return $this->formatDate($data);
        }

        if ($data instanceof \Throwable) {
            return $this->normalizeException($data, $depth);
        }

        if ($data instanceof \JsonSerializable) {
            return $data;
        }

        if ('__PHP_Incomplete_Class' === get_class($data)) {
            $accessor = new \ArrayObject($data);

            return ['__PHP_Incomplete_Class' => (string) ($accessor['__PHP_Incomplete_Class_Name'] ?? 'Unknown')];
        }

        if (method_exists($data, '__toString')) {
            try {
                return (string) $data;
            } catch (\Throwable $exception) {
                return json_decode($this->toJson($data, true), true);
            }
        }

        return $data;
    }

    /**
     * Normalizes a log record.
     */
    private function normalizeRecord($record): array
    {
        $context = [];
        $extra = [];
        $level = 'UNKNOWN';
        $message = '';

        if ($record instanceof LogRecord) {
            $message = $record->message;
            $level = $record->level->getName();
            $context = $record->context;
            $extra = $record->extra;
        } elseif (is_array($record)) {
            $message = (string) ($record['message'] ?? '');
            $level = strtoupper((string) ($record['level_name'] ?? $record['level']['name'] ?? 'UNKNOWN'));
            $context = (array) ($record['context'] ?? []);
            $extra = (array) ($record['extra'] ?? []);
        }

        $data = [
            'message' => $message,
            'level' => $level,
        ];

        $exception = $context['exception'] ?? null;

        if ($exception instanceof \Throwable) {
            $data['exception'] = $this->normalizeException($exception);

            unset($context['exception']);
        }

        if ([] !== $context) {
            $data['context'] = $this->normalizeArray($context, 0);
        }

        if ([] !== $extra) {
            $data['extra'] = $this->normalizeArray($extra, 0);
        }

        return $data;
    }

    /**
     * Normalizes a scalar value.
     */
    private function normalizeScalar($data)
    {
        if (!is_float($data)) {
            return $data;
        }

        if (is_infinite($data)) {
            return ($data > 0 ? '' : '-').'INF';
        }

        if (is_nan($data)) {
            return 'NaN';
        }

        return $data;
    }

    /**
     * Normalizes a SoapFault.
     */
    private function normalizeSoapFault(\SoapFault $fault): array
    {
        $data = [];

        if (isset($fault->faultcode)) {
            $data['faultcode'] = $fault->faultcode;
        }

        if (isset($fault->faultactor)) {
            $data['faultactor'] = $fault->faultactor;
        }

        if (isset($fault->detail)) {
            $data['detail'] = $this->normalizeSoapFaultDetail($fault->detail);
        }

        return $data;
    }

    /**
     * Normalizes SoapFault detail.
     */
    private function normalizeSoapFaultDetail($detail)
    {
        if (is_string($detail)) {
            return $detail;
        }

        if (is_object($detail) || is_array($detail)) {
            return $this->toJson($detail, true);
        }

        return $detail;
    }

    /**
     * Return the JSON representation of a value.
     */
    private function toJson($data, bool $ignoreErrors = false): string
    {
        return Utils::jsonEncode($data, $this->jsonEncodeOptions, $ignoreErrors);
    }
}
