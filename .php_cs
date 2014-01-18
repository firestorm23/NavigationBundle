<?php

/**
 * @link http://cs.sensiolabs.org/
 * @link http://symfony.com/doc/current/components/finder.html
 */

$finder = Symfony\CS\Finder\DefaultFinder::create()
    ->notName('LICENSE')
    ->notName('README.md')
    ->notName('.php_cs')
    ->notName('composer.*')
    ->notName('phpunit.xml*')
    ->notName('*.phar')
    ->notName('bootstrap.php.cache')
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)

    // Ignore Resources/{meta,doc,public,sql} and fixture dirs
    ->files()->filter(function (\SplFileInfo $file) {
        if (preg_match('#(Resources/(meta|doc|public|sql))|/fixtures/#i', str_replace('\\', '/', $file->getPath()))) {
            return false;
        }

        return true;
    })

    ->in(__DIR__ . '/src/')
    ->in(__DIR__ . '/tests/')
;

return Symfony\CS\Config\Config::create()
    ->finder($finder)
    ->fixers(Symfony\CS\FixerInterface::ALL_LEVEL)
;
