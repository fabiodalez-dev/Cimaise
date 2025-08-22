<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class CamerasController
{
    public function __construct(private Database $db, private Twig $view) {}

    public function index(Request $request, Response $response): Response
    {
        $page = max(1, (int)($request->getQueryParams()['page'] ?? 1));
        $per = 10; $off = ($page-1)*$per; $pdo = $this->db->pdo();
        $total = (int)$pdo->query('SELECT COUNT(*) FROM cameras')->fetchColumn();
        $stmt = $pdo->prepare('SELECT id, make, model FROM cameras ORDER BY make, model LIMIT :l OFFSET :o');
        $stmt->bindValue(':l', $per, \PDO::PARAM_INT); $stmt->bindValue(':o', $off, \PDO::PARAM_INT); $stmt->execute();
        return $this->view->render($response, 'admin/cameras/index.twig', [
            'items' => $stmt->fetchAll(), 'page'=>$page, 'pages'=>(int)ceil(max(0,$total)/$per)
        ]);
    }

    public function create(Request $request, Response $response): Response
    { return $this->view->render($response, 'admin/cameras/create.twig', ['csrf'=>$_SESSION['csrf']??'']); }

    public function store(Request $request, Response $response): Response
    {
        $d=(array)$request->getParsedBody(); $make=trim((string)($d['make']??'')); $model=trim((string)($d['model']??''));
        if($make===''||$model===''){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Make e Model obbligatori']; return $response->withHeader('Location','/admin/cameras/create')->withStatus(302);}        
        try{ $this->db->pdo()->prepare('INSERT INTO cameras(make, model) VALUES(:a,:b)')->execute([':a'=>$make,':b'=>$model]); $_SESSION['flash'][]=['type'=>'success','message'=>'Camera creata']; }
        catch(\Throwable $e){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Errore: '.$e->getMessage()]; return $response->withHeader('Location','/admin/cameras/create')->withStatus(302);}        
        return $response->withHeader('Location','/admin/cameras')->withStatus(302);
    }

    public function edit(Request $request, Response $response, array $args): Response
    { $id=(int)($args['id']??0); $st=$this->db->pdo()->prepare('SELECT * FROM cameras WHERE id=:id'); $st->execute([':id'=>$id]); $it=$st->fetch(); if(!$it){return $response->withStatus(404);} return $this->view->render($response,'admin/cameras/edit.twig',['item'=>$it,'csrf'=>$_SESSION['csrf']??'']); }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id=(int)($args['id']??0); $d=(array)$request->getParsedBody(); $make=trim((string)($d['make']??'')); $model=trim((string)($d['model']??''));
        if($make===''||$model===''){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Make e Model obbligatori']; return $response->withHeader('Location','/admin/cameras/'.$id.'/edit')->withStatus(302);}        
        try{ $this->db->pdo()->prepare('UPDATE cameras SET make=:a, model=:b WHERE id=:id')->execute([':a'=>$make,':b'=>$model,':id'=>$id]); $_SESSION['flash'][]=['type'=>'success','message'=>'Camera aggiornata']; }
        catch(\Throwable $e){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Errore: '.$e->getMessage()]; }
        return $response->withHeader('Location','/admin/cameras')->withStatus(302);
    }

    public function delete(Request $request, Response $response, array $args): Response
    { $id=(int)($args['id']??0); try{$this->db->pdo()->prepare('DELETE FROM cameras WHERE id=:id')->execute([':id'=>$id]); $_SESSION['flash'][]=['type'=>'success','message'=>'Camera eliminata'];}catch(\Throwable $e){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Errore: '.$e->getMessage()];} return $response->withHeader('Location','/admin/cameras')->withStatus(302); }
}

