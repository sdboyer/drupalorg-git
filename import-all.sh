#!/bin/sh

CONCURRENCY=3 # set to the number of cores you want to pwn with the migration process
REPOSITORY=/cvs/drupal # replace with path to the root of the local repository
DESTINATION=/var/git/repositories
LOG_PATH=logs
DIFFLOG_PATH=difflog
PHP="/usr/bin/php"

# Remove empty repos. They're pointless in git, and the import barfs when we point at an empty directory.
# find . -maxdepth 1 -type d -empty -exec rm -r {} \;

mkdir -p $DESTINATION/projects
# migrate all the parent dirs for which each child receives a repo in the shared, top-level namespace (projects)
for TYPE in modules themes theme-engines profiles; do
    mkdir -p $LOG_PATH/$TYPE $DIFFLOG_PATH/$TYPE
    PREFIX="contributions/$TYPE"
    find $REPOSITORY/$PREFIX/ -mindepth 1 -maxdepth 1 -type d | xargs -I% basename % | egrep -v "Attic" | xargs --max-proc $CONCURRENCY -I% sh -c "$PHP import-project.php ./cvs2git.options $REPOSITORY $PREFIX/% $DESTINATION/projects/%.git | tee $LOG_PATH/$TYPE/%.log"
done

# migrate sandboxes into their frozen location
mkdir -p $DESTINATION/sandboxes $LOG_PATH/sandboxes
find $REPOSITORY/contributions/sandbox/ -mindepth 1 -maxdepth 1 -type d | xargs -I% basename % | egrep -v "Attic" | xargs --max-proc $CONCURRENCY -I% sh -c "$PHP import-project.php ./cvs2git.options $REPOSITORY contributions/sandbox/% $DESTINATION/sandboxes/%/cvs-imported.git | tee $LOG_PATH/sandboxes/%.log"

# do special-case handling for docs, tricks, and finally core.
$PHP import-project.php ./cvs2git.options $REPOSITORY contributions/docs $DESTINATION/projects/docs.git | tee $LOG_PATH/docs.log
$PHP import-project.php ./cvs2git.options $REPOSITORY contributions/tricks $DESTINATION/projects/tricks.git | tee $LOG_PATH/tricks.log
$PHP import-project.php ./cvs2git.options $REPOSITORY drupal $DESTINATION/projects/drupal.git | tee $LOG_PATH/core.log

