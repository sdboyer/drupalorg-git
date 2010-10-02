#!/usr/bin/env sed -Ef

# Should be run like this:
# sed -Ei '' -f strip-cvs-id-tags.sed strip-cvs-id-tags.test.txt

# Tag on a line by itself, preceeded by one or more spaces/comment characters.
/^\s*[\/\*#].*\$[Ii][Dd]/ d


# Tag preceeded by the PHP tag.
s/^\s*<\?(php)* [\/\*#].*\$[Ii][Dd].*/<?php/g

