<?php

use App\Mcp\Servers\AgentServer;
use App\Mcp\Servers\UserServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

// OAuth discovery and dynamic client registration: an MCP client signs the user
// in at the consent screen and gets its token itself.
Mcp::oauthRoutes();

// Both read and act as the user the token was issued to, and only with a token
// issued for them: the scope keeps a token made for anything else out.
Mcp::web('mcp/user', UserServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use')]);

Mcp::web('mcp/agent', AgentServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use')]);
