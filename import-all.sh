#!/bin/sh

if [ ! $C2G_CONCURRENCY ]; then
    C2G_CONCURRENCY=8 # set to the number of cores you want to pwn with the migration process
fi
if [ ! $C2G_REPOSITORY ]; then
    C2G_REPOSITORY=/var/git/cvsmirror # replace with path to the root of the local repository
fi
if [ ! $C2G_DESTINATION ]; then
    C2G_DESTINATION=/var/git/repositories
fi
if [ ! $C2G_PHP ]; then
    C2G_PHP="/usr/bin/php"
fi

mkdir -p $C2G_DESTINATION/project

# do special-case handling for docs, tricks, and finally core. Do these first in the background because they take a while.
$C2G_PHP import-project.php ./cvs2git.options $C2G_REPOSITORY contributions/docs $C2G_DESTINATION/project/docs.git &
$C2G_PHP import-project.php ./cvs2git.options $C2G_REPOSITORY contributions/tricks $C2G_DESTINATION/project/tricks.git &
$C2G_PHP import-project.php ./cvs2git.options $C2G_REPOSITORY drupal $C2G_DESTINATION/project/drupal.git &

# migrate all the parent dirs for which each child receives a repo in the shared, top-level namespace (project)
for TYPE in modules themes theme-engines profiles; do
    PREFIX="contributions/$TYPE"
    find $C2G_REPOSITORY/$PREFIX/ -mindepth 1 -maxdepth 1 -type d -not -empty | xargs -I% basename % | egrep -v "Attic" | xargs --max-proc $C2G_CONCURRENCY -I% sh -c "$C2G_PHP import-project.php ./cvs2git.options $C2G_REPOSITORY $PREFIX/% $C2G_DESTINATION/project/%.git"
done

