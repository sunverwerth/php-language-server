<?php
declare(strict_types = 1);

namespace LanguageServer;

use LanguageServer\Protocol\{
    ServerCapabilities,
    ClientCapabilities,
    TextDocumentSyncKind,
    Message,
    InitializeResult,
    CompletionOptions,
    MessageType
};
use LanguageServer\FilesFinder\{FilesFinder, ClientFilesFinder, FileSystemFilesFinder};
use LanguageServer\ContentRetriever\{ContentRetriever, ClientContentRetriever, FileSystemContentRetriever};
use LanguageServer\Index\{DependenciesIndex, GlobalIndex, Index, ProjectIndex, StubsIndex};
use LanguageServer\Cache\{FileSystemCache, ClientCache};
use AdvancedJsonRpc;
use Sabre\Event\Promise;
use function Sabre\Event\coroutine;
use Throwable;
use Webmozart\PathUtil\Path;

use function LanguageServer\pathToUri;
use function LanguageServer\uriToPath;

class LanguageServer extends AdvancedJsonRpc\Dispatcher
{
    /**
     * Handles textDocument/* method calls
     *
     * @var Server\TextDocument
     */
    public $textDocument;

    /**
     * Handles workspace/* method calls
     *
     * @var Server\Workspace
     */
    public $workspace;

    /**
     * @var Server\Window
     */
    public $window;

    public $telemetry;
    public $completionItem;
    public $codeLens;

    /**
     * @var ProtocolReader
     */
    protected $protocolReader;

    /**
     * @var ProtocolWriter
     */
    protected $protocolWriter;

    /**
     * @var LanguageClient
     */
    protected $client;

    /**
     * @var FilesFinder
     */
    protected $filesFinder;

    /**
     * @var ContentRetriever
     */
    protected $contentRetriever;

    /**
     * @var PhpDocumentLoader
     */
    protected $documentLoader;

    /**
     * The parsed composer.json file in the project, if any
     *
     * @var \stdClass
     */
    protected $composerJson;

    /**
     * The parsed composer.lock file in the project, if any
     *
     * @var \stdClass
     */
    protected $composerLock;

    /**
     * @var GlobalIndex
     */
    protected $globalIndex;

    /**
     * @var ProjectIndex
     */
    protected $projectIndex;

    /**
     * @var DefinitionResolver
     */
    protected $definitionResolver;

    /**
     * @var CodeDB
     */
    private $db;

    /**
     * @var string|null
     */
    private $rootPath;

    /**
     * @param ProtocolReader  $reader
     * @param ProtocolWriter $writer
     */
    public function __construct(ProtocolReader $reader, ProtocolWriter $writer)
    {
        parent::__construct($this, '/');
        $this->protocolReader = $reader;
        $this->protocolReader->on('close', function () {
            $this->shutdown();
            $this->exit();
        });
        $this->protocolReader->on('message', function (Message $msg) {
            coroutine(function () use ($msg) {
                // Ignore responses, this is the handler for requests and notifications
                if (AdvancedJsonRpc\Response::isResponse($msg->body)) {
                    return;
                }
                $result = null;
                $error = null;
                try {
                    // Invoke the method handler to get a result
                    $result = yield $this->dispatch($msg->body);
                } catch (AdvancedJsonRpc\Error $e) {
                    // If a ResponseError is thrown, send it back in the Response
                    $error = $e;
                } catch (Throwable $e) {
                    // If an unexpected error occurred, send back an INTERNAL_ERROR error response
                    $error = new AdvancedJsonRpc\Error(
                        (string)$e,
                        AdvancedJsonRpc\ErrorCode::INTERNAL_ERROR,
                        null,
                        $e
                    );
                }
                // Only send a Response for a Request
                // Notifications do not send Responses
                if (AdvancedJsonRpc\Request::isRequest($msg->body)) {
                    if ($error !== null) {
                        $responseBody = new AdvancedJsonRpc\ErrorResponse($msg->body->id, $error);
                    } else {
                        $responseBody = new AdvancedJsonRpc\SuccessResponse($msg->body->id, $result);
                    }
                    $this->protocolWriter->write(new Message($responseBody));
                }
            })->otherwise('\\LanguageServer\\crash');
        });
        $this->protocolWriter = $writer;
        $this->client = new LanguageClient($reader, $writer);
        $this->window = $this->client->window;
    }

    /**
     * The initialize request is sent as the first request from the client to the server.
     *
     * @param ClientCapabilities $capabilities The capabilities provided by the client (editor)
     * @param string|null $rootPath The rootPath of the workspace. Is null if no folder is open.
     * @param int|null $processId The process Id of the parent process that started the server. Is null if the process has not been started by another process. If the parent process is not alive then the server should exit (see exit notification) its process.
     * @return Promise <InitializeResult>
     */
    public function initialize(ClientCapabilities $capabilities, string $rootPath = null, int $processId = null): Promise
    {
        $this->rootPath = $rootPath;

        return coroutine(function () use ($capabilities, $rootPath, $processId) {

            if ($capabilities->xfilesProvider) {
                $this->filesFinder = new ClientFilesFinder($this->client);
            } else {
                $this->filesFinder = new FileSystemFilesFinder;
            }

            if ($capabilities->xcontentProvider) {
                $this->contentRetriever = new ClientContentRetriever($this->client);
            } else {
                $this->contentRetriever = new FileSystemContentRetriever;
            }

            /*$this->documentLoader = new PhpDocumentLoader(
                $this->contentRetriever,
                $this->projectIndex,
                $this->definitionResolver
            );*/

            $this->db = new CodeDB\Repository();
            fwrite(STDERR, getcwd());
            if ($rootPath !== null) {
                if (file_exists('phpls.cache')) {
                    $this->window->logMessage(MessageType::INFO, 'Cache DB found. Loading...');
                    yield;
                    try {
                        $db = unserialize(file_get_contents('phpls.cache'));
                        $this->window->logMessage(MessageType::INFO, 'Complete');
                        yield;
                        if ($db) {
                            $this->db = $db;
                        }
                    }
                    catch (\Exception $e) {
                        $this->window->logMessage(MessageType::ERROR, 'Error while loading cache: ' . $e->getMessage());
                        yield;
                    }
                }

                $this->window->logMessage(MessageType::INFO, 'Checking project for modifications...');
                yield;

                $parser = new \Microsoft\PhpParser\Parser();
                $this->filesFinder->find('**/*.php')->then(function($uri) use ($parser) {

                    $this->contentRetriever->retrieve($uri)->then(function($src) {
                        if (isset($this->db->files[$uri]) && hash('sha256', $src) === $this->db->files[$uri]->hash()) {
                            return;
                        }

                        $parsestart = microtime(true);
                        $ast = $parser->parseSourceFile($src, $uri);
                        $collector = new CodeDB\Collector($this->db, $uri, $ast);
                        $collector->iterate($ast);
                        $parseend = microtime(true);
                        $collector->file->parseTime = $parseend-$parsestart;
                    });
                });

                $this->window->logMessage(MessageType::INFO, 'Complete');
                yield;
            }

            if ($this->textDocument === null) {
                $this->textDocument = new Server\TextDocument($this->client, $this->db);
            }

            if ($this->workspace === null) {
                $this->workspace = new Server\Workspace($this->client, $this->db);
            }

            $serverCapabilities = new ServerCapabilities();
            // Ask the client to return always full documents (because we need to rebuild the AST from scratch)
            $serverCapabilities->textDocumentSync = TextDocumentSyncKind::FULL;
            // Support "Find all symbols"
            $serverCapabilities->documentSymbolProvider = true;
            // Support "Find all symbols in workspace"
            $serverCapabilities->workspaceSymbolProvider = true;
            // Support "Go to definition"
            $serverCapabilities->definitionProvider = true;
            // Support "Find all references"
            $serverCapabilities->referencesProvider = true;
            // Support "Hover"
            $serverCapabilities->hoverProvider = true;
            // Support "Completion"
            $serverCapabilities->completionProvider = new CompletionOptions;
            $serverCapabilities->completionProvider->resolveProvider = false;
            $serverCapabilities->completionProvider->triggerCharacters = ['$', '>'];
            // Support global references
            $serverCapabilities->xworkspaceReferencesProvider = true;
            $serverCapabilities->xdefinitionProvider = true;
            $serverCapabilities->xdependenciesProvider = true;

            return new InitializeResult($serverCapabilities);
        });
    }

    /**
     * The shutdown request is sent from the client to the server. It asks the server to shut down, but to not exit
     * (otherwise the response might not be delivered correctly to the client). There is a separate exit notification that
     * asks the server to exit.
     *
     * @return void
     */
    public function shutdown()
    {
        if ($this->rootPath !== null) {
            file_put_contents('phpls.cache', serialize($this->db));
        }
        unset($this->project);
    }

    /**
     * A notification to ask the server to exit its process.
     *
     * @return void
     */
    public function exit()
    {
        exit(0);
    }

    /**
     * Called before indexing, can return a Promise
     *
     * @param string $rootPath
     */
    protected function beforeIndex(string $rootPath)
    {
    }
}
