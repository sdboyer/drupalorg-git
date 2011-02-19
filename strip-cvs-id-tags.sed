#!/usr/bin/env sed -f

# Should be run like this:
# sed -i '' -f strip-cvs-id-tags.sed strip-cvs-id-tags.test.txt
# BSD sed needs the -E flag for all these rules to work. Not required on
# GNU sed.

# Tag on a line by itself, preceeded by one or more spaces/comment characters.
/^ *[\/\*#;{-].*\$[Ii][Dd]: .*,v [1-9][0-9\.]* 20[0-9][0-9].*\$/ d
/^ *[\/\*#;{-].*\$[Ii][Dd]\$/ d
# Invalid (unterminated) version of the above.
/^ *[\/\*#;{-].*\$[Ii][Dd]:? *$/ d

# Tag on a line by itself, preceeded by nothing or whitespace.
/^ *\$[Ii][Dd]: .*,v [1-9][0-9\.]* 20[0-9][0-9].*\$/ d
/^ *\$[Ii][Dd]\$/ d
# Invalid (unterminated) version of the above.
/^ *\$[Ii][Dd]:? *$/ d

# Tag preceeded by the PHP tag.
s/^ *<\?(php)* [\/\*#].*\$[Ii][Dd].*\$/<?php/g

# Tag inside XML/HTML comment.
/^ *<!---* *\$[Ii][Dd].*\$/ d

