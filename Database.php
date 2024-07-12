<?php

namespace FpDbTest;

use Exception;
use mysqli;

class Database implements DatabaseInterface
{
    private mysqli $mysqli;

    public function __construct(mysqli $mysqli)
    {
        $this->mysqli = $mysqli;
    }

    public function buildQuery(string $query, array $args = []): string
    {
        $paramIndex = 0;
        $inBlock = false;
        $blockContent = '';
        $resultQuery = '';

        for ($i = 0; $i < strlen($query); $i++) {
            $char = $query[$i];
           
            if ($char === '{') {
                if ($inBlock) {
                    throw new Exception("Nested blocks are not allowed");
                }
                $inBlock = true;
                $blockContent = '';
            } elseif ($char === '}') {
                if (!$inBlock) {
                    throw new Exception("Unmatched closing block");
                }
                $inBlock = false;
                if (!$this->shouldSkipBlock($blockContent, $args)) {
                    $resultQuery .= $blockContent;
                }
            } elseif ($inBlock) {
                $blockContent .= $char;
            } else {
                $resultQuery .= $char;
            }
        }

        if ($inBlock) {
            throw new Exception("Unclosed block");
        }

        $resultQuery = preg_replace_callback('/\?([daf#a]?)/', function($matches) use (&$args, &$paramIndex, $resultQuery) {
            if (!isset($args[$paramIndex])) {
                throw new Exception("Missing parameter for placeholder ?");
            }

            $value = $args[$paramIndex];
            $specifier = $matches[1];
            $paramIndex++;
           
            if ($value === $this->skip()) {
                return '__S';
            }

            switch ($specifier) {
                case 'd':
                    if ($value === null) {
                        return 'NULL';
                    }
                    return (int)$value;
                case 'f':
                    if ($value === null) {
                        return 'NULL';
                    }
                    return (float)$value;
                case 'a':
                    if (!is_array($value)) {
                        throw new Exception("Array expected for placeholder ?a");
                    }
                    if (array_keys($value) !== range(0, count($value) - 1)) {
                        // Associative array
                        $setClauses = [];
                        foreach ($value as $key => $val) {
                            $setClauses[] = $this->escapeIdentifier($key) . ' = ' . $this->formatValue($val);
                        }
                        return implode(', ', $setClauses);
                    } else {
                        // Indexed array
                        return implode(', ', array_map([$this, 'formatValue'], $value));
                    }
                case '#':
                    if (is_array($value)) {
                        return implode(', ', array_map([$this, 'escapeIdentifier'], $value));
                    }
                    return $this->escapeIdentifier($value);
                case '':
                    return $this->formatValue($value);
                default:
                    throw new Exception("Unknown specifier: $specifier");
            }
        }, $resultQuery);
        
        $resultQuery = preg_replace('/ AND \w+ = __S/', '', $resultQuery);
        $resultQuery = preg_replace('/ \w+ = __S/', '', $resultQuery);

        return $resultQuery;
    }

    private function shouldSkipBlock(string $blockContent, array $args): bool
    {
        preg_match_all('/\?([daf#a]?)/', $blockContent, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as $match) {
            $paramIndex = substr_count($blockContent, '?', 0, $match[1]);
            if ($args[$paramIndex] === $this->skip()) {
                return true;
            }
        }
        return false;
    }

    private function formatValue($value): string
    {
        if ($value === null) {
            return 'NULL';
        } elseif (is_int($value)) {
            return (string)$value;
        } elseif (is_float($value)) {
            return (string)$value;
        } elseif (is_bool($value)) {
            return $value ? '1' : '0';
        } elseif (is_string($value)) {
            return $this->escapeString($value);
        } else {
            throw new Exception("Unsupported value type");
        }
    }

    private function escapeString(string $value): string
    {
        return "'" . $this->mysqli->real_escape_string($value) . "'";
    }

    private function escapeIdentifier(string $value): string
    {
        return "`" . str_replace('`', '``', $value) . "`";
    }

    public function skip()
    {
        return '__SKIP__';
    }
}