<?php

if (!defined('LOGLEVEL')) {
  // Let an environment variable set the log level
  $level = getenv('LOGLEVEL');
  if (is_string($level)) {
    define('LOGLEVEL', (int) $level);
  }
  else {
    // Or default to 'normal'
    define('LOGLEVEL', 3);
  }
}

function git_invoke($command, $fail_safe = FALSE, $repository_path = NULL, $env = NULL) {
  if (!isset($env)) {
    $env = $_ENV;
  }
  if ($repository_path) {
    $env['GIT_DIR'] = $repository_path;
  }

  $descriptor_spec = array(
    1 => array('pipe', 'w'),
    2 => array('pipe', 'w'),
  );

  git_log('Invoking ' . $command, 'DEBUG');

  $process = proc_open($command, $descriptor_spec, $pipes, NULL, $env);
  if (is_resource($process)) {
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    $return_code = proc_close($process);

    if ($return_code != 0 && !$fail_safe) {
      throw new Exception("Invocation of '" . $command . "' failed with return code " . $return_code .": \n" . $stdout . $stderr);
    }

    return $stdout;
  }
}

function is_empty_dir($dir){
  $files = @scandir($dir);
  return (!$files || count($files) <= 2);
}

function is_cvs_dir($dir) {
  $files = @scandir($dir);
  $current_files = strpos(implode(' ', $files), ',v') !== FALSE;
  $attic = array_search('Attic', $files);
  return $current_files || $attic;
}

/**
 * Recursively delete a directory on a local filesystem.
 *
 * @param string $path
 *   The path to the directory.
 */
function rmdirr($path) {
  foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
    $item->isFile() ? unlink($item) : rmdir($item);
  }
  rmdir($path);
}

function git_log($message, $level = 'NORMAL', $project = NULL) {
  $loglevels = array(
    'WARN' => 1,
    'QUIET' => 2,
    'NORMAL' => 3,
    'INFO' => 4,
    'DEBUG' => 5,
  );
  if (LOGLEVEL !== 0 && LOGLEVEL >= $loglevels[$level]) {
    if (isset($project)) {
      echo "[" . date('Y-m-d H:i:s') . "] [$level] [$project] $message\n";
    }
    else {
      echo "[" . date('Y-m-d H:i:s') . "] [$level] $message\n";
    }
  }
}
