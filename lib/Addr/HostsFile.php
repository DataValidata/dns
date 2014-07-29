<?php

namespace Addr;

class HostsFile implements Resolver
{
    /**
     * @var NameValidator
     */
    private $nameValidator;

    /**
     * Path to hosts file
     *
     * @var string
     */
    private $path;

    /**
     * Mapped names from hosts file
     *
     * @var array
     */
    private $data;

    /**
     * The file modification time when the last reload was performed
     *
     * @var int
     */
    private $lastModTime = 0;

    /**
     * Constructor
     *
     * @param NameValidator $nameValidator
     * @param string $path
     * @throws \LogicException
     */
    public function __construct(NameValidator $nameValidator, $path = null)
    {
        $this->nameValidator = $nameValidator;

        if ($path === null) {
            $path = stripos(PHP_OS, 'win') === 0 ? 'C:\Windows\system32\drivers\etc\hosts' : '/etc/hosts';
        }

        if (!file_exists($path)) {
            throw new \LogicException($path . ' does not exist');
        } else if (!is_file($path)) {
            throw new \LogicException($path . ' is not a file');
        } else if (!is_readable($path)) {
            throw new \LogicException($path . ' is not readable');
        }

        $this->path = $path;
    }

    /**
     * Parse a hosts file into an array
     */
    private function reload()
    {
        $this->data = [
            AddressModes::INET4_ADDR => [],
            AddressModes::INET6_ADDR => [],
        ];
        $key = null;

        foreach (file($this->path) as $line) {
            $line = trim($line);
            if ($line !== '' && $line[0] === '#') {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                $key = AddressModes::INET4_ADDR;
            } else if (filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
                $key = AddressModes::INET6_ADDR;
            } else {
                continue;
            }

            for ($i = 1, $l = count($parts); $i < $l; $i++) {
                if ($this->nameValidator->validate($parts[$i])) {
                    $this->data[$key][$parts[$i]] = $parts[0];
                }
            }
        }
    }

    /**
     * Ensure the loaded data is current
     */
    private function ensureDataIsCurrent()
    {
        clearstatcache(true, $this->path);
        $modTime = filemtime($this->path);

        if ($modTime > $this->lastModTime) {
            $this->reload();
            $this->lastModTime = $modTime;
        }
    }

    /**
     * Look up a name in the hosts file
     *
     * @param string $name
     * @param int $mode
     * @param callable $callback
     * @return array|null
     */
    public function resolve($name, $mode, callable $callback)
    {
        $this->ensureDataIsCurrent();

        $have4 = isset($this->data[AddressModes::INET4_ADDR][$name]);
        $have6 = isset($this->data[AddressModes::INET6_ADDR][$name]);
        $want4 = (bool)($mode & AddressModes::INET4_ADDR);
        $want6 = (bool)($mode & AddressModes::INET6_ADDR);
        $pref6 = (bool)($mode & AddressModes::PREFER_INET6);

        if ($have6 && $want6 && (!$want4 || !$have4 || $pref6)) {
            $callback($this->data[AddressModes::INET6_ADDR][$name], AddressModes::INET6_ADDR);
        } else if ($have4 && $want4) {
            $callback($this->data[AddressModes::INET4_ADDR][$name], AddressModes::INET4_ADDR);
        } else {
            $callback(null, ResolutionErrors::ERR_NO_RECORD);
        }
    }
}
