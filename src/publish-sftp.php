<?php
/**
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License version 3.0
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    George van Engers | PrestaHaven.com
 * @copyright Since 2026 George van Engers
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

/*
 * The SFTP connection of `ps-module publish` and `ps-module demo-site` (loaded by publish_connect() once phpseclib is
 * available). With -v it reports every remote action: commands with exit code and time, uploads with size, deletes,
 * new folders and permission changes. Uploaded content is never shown (it can hold settings or credentials), and
 * every line goes through the mask of publish.php.
 */

declare(strict_types=1);

/**
 * phpseclib's SFTP client that reports what it does when verbose output is on (ps-module -v).
 */
class PublishSftp extends \phpseclib3\Net\SFTP
{
    /**
     * Runs a command on the server; reports it with its exit code and time.
     *
     * @param string $command
     * @param callable|null $callback
     *
     * @return string|bool
     */
    public function exec($command, $callback = null)
    {
        ps_vlog('ssh $ ' . $command);
        $start = microtime(true);
        $result = parent::exec($command, $callback);
        ps_vdone('ssh', $start, is_int($this->getExitStatus()) ? $this->getExitStatus() : '?');

        return $result;
    }

    /**
     * Uploads a local file or a string; reports the target and the size, never the content.
     *
     * @param string $remote_file
     * @param string|resource $data
     * @param int $mode
     * @param int $start
     * @param int $local_start
     * @param callable|null $progressCallback
     *
     * @return bool
     */
    public function put($remote_file, $data, $mode = self::SOURCE_STRING, $start = -1, $local_start = -1, $progressCallback = null)
    {
        $local = ($mode & self::SOURCE_LOCAL_FILE) && is_string($data);
        $size = $local ? (int) @filesize($data) : (is_string($data) ? strlen($data) : 0);
        $began = microtime(true);
        $ok = parent::put($remote_file, $data, $mode, $start, $local_start, $progressCallback);
        ps_vlog(sprintf('sftp put %s%s (%s) %s after %.1fs', $local ? $data . ' -> ' : '', $remote_file, publish_size($size), $ok ? 'ok' : 'FAILED', microtime(true) - $began));

        return $ok;
    }

    /**
     * Downloads a file; reports the source and where it went.
     *
     * @param string $remote_file
     * @param string|bool|resource|callable $local_file
     * @param int $offset
     * @param int $length
     * @param callable|null $progressCallback
     *
     * @return string|bool
     */
    public function get($remote_file, $local_file = false, $offset = 0, $length = -1, $progressCallback = null)
    {
        $began = microtime(true);
        $result = parent::get($remote_file, $local_file, $offset, $length, $progressCallback);
        ps_vlog(sprintf('sftp get %s%s %s after %.1fs', $remote_file, is_string($local_file) ? ' -> ' . $local_file : '', $result === false ? 'FAILED' : 'ok', microtime(true) - $began));

        return $result;
    }

    /**
     * Removes a file or folder on the server; reports it.
     *
     * @param string $path
     * @param bool $recursive
     *
     * @return bool
     */
    public function delete($path, $recursive = true)
    {
        $ok = parent::delete($path, $recursive);
        ps_vlog('sftp delete ' . $path . ($ok ? '' : ' (nothing removed)'));

        return $ok;
    }

    /**
     * Creates a folder on the server; reports it.
     *
     * @param string $dir
     * @param int $mode
     * @param bool $recursive
     *
     * @return bool
     */
    public function mkdir($dir, $mode = -1, $recursive = false)
    {
        $ok = parent::mkdir($dir, $mode, $recursive);
        ps_vlog('sftp mkdir ' . $dir . ($mode !== -1 ? sprintf(' (mode %o)', $mode) : '') . ($ok ? '' : ' FAILED'));

        return $ok;
    }

    /**
     * Changes the permissions of a file on the server; reports it.
     *
     * @param int $mode
     * @param string $filename
     * @param bool $recursive
     *
     * @return mixed
     */
    public function chmod($mode, $filename, $recursive = false)
    {
        $result = parent::chmod($mode, $filename, $recursive);
        ps_vlog(sprintf('sftp chmod %o %s%s', $mode, $filename, $result === false ? ' FAILED' : ''));

        return $result;
    }
}
