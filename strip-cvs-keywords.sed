#!/usr/bin/env sed -Ef

# Should be run like this:
# sed -Ei '' -f strip-cvs-keywords.sed strip-cvs-keywords.test.txt

# Tag on a line by itself, preceeded by one or more spaces/comment characters.
/^ *[\/\*#;{-].*\$[Ii][Dd].*\$/ d
# Invalid (unterminated) version of the above.
/^ *[\/\*#;{-].*\$[Ii][Dd]:? *$/ d

# Tag on a line by itself, preceeded by nothing or whitespace.
/^ *\$[Ii][Dd]:.*\$/ d
/^ *\$[Ii][Dd]\$/ d
# Invalid (unterminated) version of the above.
/^ *\$[Ii][Dd]:? *$/ d

# Tag preceeded by the PHP tag.
s/^ *<\?(php)* [\/\*#].*\$[Ii][Dd].*\$/<?php/g

# Tag inside XML/HTML comment.
/^ *<!---* *\$[Ii][Dd].*\$/ d

