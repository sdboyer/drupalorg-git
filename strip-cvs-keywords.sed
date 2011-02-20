#!/usr/bin/env sed -f

# Should be run like this:
# sed -i '' -f strip-cvs-id-keywords.sed strip-cvs-id-keywords.test.txt
# BSD sed needs the -E flag for all these rules to work. Not required on
# GNU sed.

# Keyword on a line by itself, preceeded by one or more spaces/comment characters.
/^ *[\/\*#;{-].*\$[Ii][Dd]: .*,v [1-9][0-9\.]* 20[0-9][0-9].*\$/ d
/^ *[\/\*#;{-].*\$[Ii][Dd]\$/ d
# Invalid (unterminated) version of the above.
/^ *[\/\*#;{-].*\$[Ii][Dd]:? *$/ d

# Keyword on a line by itself, preceeded by nothing or whitespace.
/^ *\$[Ii][Dd]: .*,v [1-9][0-9\.]* 20[0-9][0-9].*\$/ d
/^ *\$[Ii][Dd]\$/ d
# Invalid (unterminated) version of the above.
/^ *\$[Ii][Dd]:? *$/ d

# Keyword preceeded by the PHP keyword.
s/^ *<\?(php)* [\/\*#].*\$[Ii][Dd].*\$/<?php/g

# Keyword inside XML/HTML comment.
/^ *<!---* *\$[Ii][Dd].*\$/ d

