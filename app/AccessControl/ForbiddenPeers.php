<?php

namespace TelegramRSS\AccessControl;

use Amp\File\File;
use TelegramRSS\Config;

use function Amp\ByteStream\splitLines;
use function Amp\File\isFile;
use function Amp\File\openFile;

final class ForbiddenPeers
{

    private static ?string $regex = null;

    /** @var array<string, string> */
    private static ?array $peers = null;

    private const FILE = ROOT_DIR . '/cache/forbidden-peers.csv';
    /** @var resource|null */
    private static ?File $filePointer = null;

    public static function add(string $peer, string $error): void {
        static $errorRegex = null;
        if ($errorRegex === null) {
            $errorRegex = Config::getInstance()->get('access.cache_peer_errors_regex');
        }
        if ($errorRegex && preg_match("/{$errorRegex}/", $error)) {
            $peer = mb_strtolower($peer);
            if ((self::$peers[$peer] ?? null) !== $error) {
                self::$peers[$peer] = $error;
                self::getFilePointer()->write(self::str_putcsv([$peer, $error]) . PHP_EOL);
            }
        }
    }

    /**
     * Return error if peer forbidden or previously failed with error
     *
     * @param string $peer
     *
     * @return string|null
     */
    public static function check(string $peer): ?string {
        $peer = mb_strtolower($peer);

        if (self::$regex === null) {
            self::$regex = (string)Config::getInstance()->get('access.forbidden_peer_regex');
        }

        if (self::$peers === null) {
            if (isFile(self::FILE)) {
                $file = self::getFilePointer();
                while(!$file->eof()){
                    foreach (splitLines($file) as $line) {
                        $csv = str_getcsv(trim($line), escape: "");
                        if (count($csv) === 2) {
                            [$oldPeer, $error] = $csv;
                            if ($oldPeer && $error) {
                                self::$peers[$oldPeer] = $error;
                            }
                        }
                    }
                }
            }
        }

        $regex = self::$regex;
        if ($regex && preg_match("/{$regex}/i", $peer)) {
            return "PEER NOT ALLOWED";
        }

        return self::$peers[$peer] ?? null;
    }

    private static function getFilePointer(): File {
        if (self::$filePointer === null) {
            self::$filePointer = openFile(self::FILE, 'cb+');
        }

        return self::$filePointer;
    }

    static function str_putcsv($input, $delimiter = ',', $enclosure = '"') {
        $fp = fopen('php://memory', 'r+b');
        fputcsv($fp, $input, $delimiter, $enclosure, "");
        rewind($fp);
        $data = rtrim(stream_get_contents($fp), "\n");
        fclose($fp);
        return $data;
    }
}