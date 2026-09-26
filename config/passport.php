<?php

return [

    /*
    | Passport serves one purpose here: Claude and ChatGPT signing in to
    | /mcp. Its endpoints answer only while that sign-in is switched on
    | under AI Tools.
    */

    'middleware' => ['mcp.sign-in'],

];
