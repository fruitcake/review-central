<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Redirect Domains
    |--------------------------------------------------------------------------
    |
    | Where an MCP client that registers itself may have the authorization
    | code sent. Its name is whatever it registers with, so these decide who
    | can be handed a token: the assistants Review Central is used with, and
    | a client on the user's own computer (http://localhost or 127.0.0.1 on
    | any port). More go under Admin -> Settings, or in MCP_REDIRECT_DOMAINS,
    | comma separated, where "*" allows any domain again.
    |
    */

    'redirect_domains' => [
        'http://localhost',
        'http://127.0.0.1',
        'https://claude.ai',
        'https://claude.com',
        'https://chatgpt.com',
        'https://vscode.dev',
        'https://insiders.vscode.dev',
    ],

    /*
    |--------------------------------------------------------------------------
    | Allowed Custom Schemes
    |--------------------------------------------------------------------------
    |
    | Native desktop OAuth clients like Cursor and VS Code use private-use URI
    | schemes (RFC 8252) for redirect callbacks instead of standard schemes
    | like HTTPS. Here, you may list which custom schemes you will allow.
    |
    */

    'custom_schemes' => [
        'claude',
        'cursor',
        'vscode',
        'vscode-insiders',
    ],

    /*
    | More of both for this installation, comma separated: an origin such as
    | https://example.com, or a scheme such as zed://. Added to the ones above
    | and to those set under Admin -> Settings.
    */

    'extra_redirects' => env('MCP_REDIRECT_DOMAINS'),

    /*
    |--------------------------------------------------------------------------
    | Authorization Server
    |--------------------------------------------------------------------------
    |
    | Here you may configure the OAuth authorization server issuer identifier
    | per RFC 8414. This value appears in your protected resource and auth
    | server metadata endpoints. When null, this defaults to `url('/')`.
    |
    */

    'authorization_server' => null,

    /*
    |--------------------------------------------------------------------------
    | Tool Search
    |--------------------------------------------------------------------------
    |
    | Here you may configure the limits enforced during tool search. The max
    | number of tool calls limits how many tools search requests can call
    | while the maximum output bytes value will limit the result sizes.
    |
    */

    'tool_search' => [
        'max_tool_calls' => 10,
        'max_output_bytes' => 65_536,
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Clients
    |--------------------------------------------------------------------------
    |
    | Here you may configure named MCP clients that connect to external MCP
    | servers. Every client may define a URL for the remote server, or a
    | command and arguments for local servers plus connection options.
    |
    */

    'clients' => [],

];
