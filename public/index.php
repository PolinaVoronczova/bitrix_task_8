<?php
require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use DI\Container;
use Arhitector\Yandex\Disk;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Flash\Messages;

define("CONFIG", require __DIR__ . '/../config/ydisk.php');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$container = new Container();
AppFactory::setContainer($container);
$app = AppFactory::create();

$container->set('yandex_disk', function () {
    return new Disk(CONFIG['ydisk']['token']);
});

$container->set('flash', function () {
    if (!isset($_SESSION['slimFlash'])) {
        $_SESSION['slimFlash'] = [];
    }
    return new Messages($_SESSION['slimFlash']);
});

$container->set('renderer', function () {
    return new \Slim\Views\PhpRenderer(__DIR__ . '/../templates');
});

$app->add(function ($request, $handler) {
    $flash = $this->get('flash');
    
    $response = $handler->handle($request);
    
    if ($this->has('renderer')) {
        $messages = $flash->getMessages();
        $this->get('renderer')->addAttribute('flash', $messages);
    }
    
    return $response;
});

$app->get('/', function ($req, $res, $args) {
    $messages = $this->get('flash')->getMessages();
    
    try {
        $disk = $this->get('yandex_disk');
        $connection = $disk->get('total_space') ? 'Подключение установлено' : 'Нет подключения';
    } catch (Exception $e) {
        $connection = 'Ошибка: ' . $e->getMessage();
    }
    
    return $this->get('renderer')->render($res, 'index.phtml', [
        'connection' => $connection,
        'messages' => $messages
    ]);
});

// Основной маршрут для отображения файлов
$app->get('/files', function (Request $request, Response $response) {
    $disk = $this->get('yandex_disk');
    
    try {
        $collection = $disk->getResources(100, 0);
        
        $files = [];
        foreach ($collection as $resource) {
            $files[] = [
                'name' => $resource->get('name'),
                'path' => $resource->getPath(),
                'size' => $resource->size,
                'type' => $resource->isDir() ? 'directory' : 'file'
            ];
        }

        $flashMessages = $this->get('flash')->getMessages();
        
        return $this->get('renderer')->render($response, 'files/index.phtml', [
            'files' => $files,
            'flash' => $flashMessages
        ]);
        
    } catch (Exception $e) {
        $this->get('flash')->addMessage('error', 'Ошибка: ' . $e->getMessage());
        return $response->withHeader('Location', '/files')->withStatus(302);
    }
});

// Загрузка файла
$app->post('/files/upload', function (Request $request, Response $response) {
    $uploadedFiles = $request->getUploadedFiles();
    $disk = $this->get('yandex_disk');
    
    if (empty($uploadedFiles['file'])) {
        $this->get('flash')->addMessage('error', 'Файл не был загружен');
        return $response->withHeader('Location', '/files')->withStatus(302);
    }

    $file = $uploadedFiles['file'];
    if ($file->getError() !== UPLOAD_ERR_OK) {
        $this->get('flash')->addMessage('error', 'Ошибка загрузки файла');
        return $response->withHeader('Location', '/files')->withStatus(302);
    }

    try {
        $resource = $disk->getResource(basename($file->getClientFilename()));
        $resource->upload($file->getFilePath());
        
        $this->get('flash')->addMessage('success', 'Файл успешно загружен: ' . $resource->getPath());
        return $response->withHeader('Location', '/files')->withStatus(302);
        
    } catch (Exception $e) {
        $this->get('flash')->addMessage('error', 'Ошибка загрузки: ' . $e->getMessage());
        return $response->withHeader('Location', '/files')->withStatus(302);
    }
});

// Удаление файла
$app->post('/files/delete', function (Request $request, Response $response) {
    $disk = $this->get('yandex_disk');
    $data = $request->getParsedBody();
    
    if (empty($data['path'])) {
        $this->get('flash')->addMessage('error', 'Не указан путь к файлу');
        return $response->withHeader('Location', '/files')->withStatus(302);
    }

    try {
        $path = urldecode($data['path']);
        $path = ltrim($path, 'disk:/');
        $path = ltrim($path, '/');
        
        $resource = $disk->getResource($path);
        
        if (!$resource->has()) {
            throw new RuntimeException("Файл '$path' не существует");
        }
        
        $resource->delete();
        $this->get('flash')->addMessage('success', 'Файл успешно удален');
        
    } catch (Exception $e) {
        $this->get('flash')->addMessage('error', 'Ошибка удаления: ' . $e->getMessage());
    }
    
    return $response->withHeader('Location', '/files')->withStatus(302);
});

// Маршрут для переименования файла
$app->get('/files/rename', function (Request $request, Response $response) {
    $queryParams = $request->getQueryParams();
    $path = $queryParams['path'] ?? '';
    
    if (empty($path)) {
        $this->get('flash')->addMessage('error', 'Не указан путь к файлу');
        return $response->withHeader('Location', '/files')->withStatus(302);
    }

    $path = urldecode($path);
    $path = str_replace('disk:/', '', $path);
    
    return $this->get('renderer')->render($response, 'files/rename.phtml', [
        'currentPath' => $path,
        'currentName' => basename($path)
    ]);
});

// Переименование файла через копирование и удаление оригинала
$app->post('/files/rename', function (Request $request, Response $response) {
    $disk = $this->get('yandex_disk');
    $data = $request->getParsedBody();
    
    if (empty($data['current_path']) || empty($data['new_name'])) {
        $this->get('flash')->addMessage('error', 'Не указаны текущий путь или новое имя');
        return $response->withHeader('Location', '/files')->withStatus(302);
    }

    try {
        $currentPath = ltrim(str_replace('disk:/', '', urldecode($data['current_path'])), '/');
        $newName = trim($data['new_name']);
        
        if (preg_match('/[\/\\\\]/', $newName)) {
            throw new RuntimeException("Имя файла содержит недопустимые символы");
        }

        $resource = $disk->getResource($currentPath);
        
        if (!$resource->has()) {
            throw new RuntimeException("Файл не существует");
        }

        $currentExt = pathinfo($currentPath, PATHINFO_EXTENSION);
        $newExt = pathinfo($newName, PATHINFO_EXTENSION);
        
        if ($currentExt !== $newExt) {
            throw new RuntimeException("Нельзя изменять расширение файла");
        }

        $newPath = (dirname($currentPath) === '.' ? '' : dirname($currentPath).'/').$newName;
        $resource->copy($newPath);
        $resource->delete();
        
        $this->get('flash')->addMessage('success', 'Файл успешно переименован');
    } catch (Exception $e) {
        $this->get('flash')->addMessage('error', 'Ошибка: '.$e->getMessage());
    }
    
    return $response->withHeader('Location', '/files')->withStatus(302);
});

$app->run();