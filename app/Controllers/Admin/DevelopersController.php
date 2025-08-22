<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DevelopersController
{
    public function __construct(private Database $db, private Twig $view) {}
    public function index(Request $request, Response $response): Response
    {
        $page=max(1,(int)($request->getQueryParams()['page']??1)); $per=10; $off=($page-1)*$per; $pdo=$this->db->pdo();
        $total=(int)$pdo->query('SELECT COUNT(*) FROM developers')->fetchColumn();
        $st=$pdo->prepare('SELECT id, name, process, notes FROM developers ORDER BY name LIMIT :l OFFSET :o');
        $st->bindValue(':l',$per,\PDO::PARAM_INT); $st->bindValue(':o',$off,\PDO::PARAM_INT); $st->execute();
        return $this->view->render($response,'admin/developers/index.twig',['items'=>$st->fetchAll(),'page'=>$page,'pages'=>(int)ceil(max(0,$total)/$per)]);
    }
    public function create(Request $r, Response $res): Response {return $this->view->render($res,'admin/developers/create.twig',['csrf'=>$_SESSION['csrf']??'']);}
    public function store(Request $r, Response $res): Response{
        $d=(array)$r->getParsedBody(); $name=trim((string)($d['name']??'')); $process=(string)($d['process']??'BW'); $notes=$d['notes']!==''?(string)$d['notes']:null;
        if($name===''){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Nome obbligatorio']; return $res->withHeader('Location','/admin/developers/create')->withStatus(302);}        
        try{ $this->db->pdo()->prepare('INSERT INTO developers(name, process, notes) VALUES(?,?,?)')->execute([$name,$process,$notes]); $_SESSION['flash'][]=['type'=>'success','message'=>'Sviluppo creato']; }
        catch(\Throwable $e){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Errore: '.$e->getMessage()]; return $res->withHeader('Location','/admin/developers/create')->withStatus(302);}        
        return $res->withHeader('Location','/admin/developers')->withStatus(302);
    }
    public function edit(Request $r, Response $res, array $args): Response{ $id=(int)($args['id']??0); $st=$this->db->pdo()->prepare('SELECT * FROM developers WHERE id=:id'); $st->execute([':id'=>$id]); $it=$st->fetch(); if(!$it){return $res->withStatus(404);} return $this->view->render($res,'admin/developers/edit.twig',['item'=>$it,'csrf'=>$_SESSION['csrf']??'']);}
    public function update(Request $r, Response $res, array $args): Response{
        $id=(int)($args['id']??0); $d=(array)$r->getParsedBody(); $name=trim((string)($d['name']??'')); $process=(string)($d['process']??'BW'); $notes=$d['notes']!==''?(string)$d['notes']:null;
        if($name===''){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Nome obbligatorio']; return $res->withHeader('Location','/admin/developers/'.$id.'/edit')->withStatus(302);}        
        try{ $this->db->pdo()->prepare('UPDATE developers SET name=?, process=?, notes=? WHERE id=?')->execute([$name,$process,$notes,$id]); $_SESSION['flash'][]=['type'=>'success','message'=>'Sviluppo aggiornato']; }
        catch(\Throwable $e){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Errore: '.$e->getMessage()]; }
        return $res->withHeader('Location','/admin/developers')->withStatus(302);
    }
    public function delete(Request $r, Response $res, array $args): Response{ $id=(int)($args['id']??0); try{$this->db->pdo()->prepare('DELETE FROM developers WHERE id=:id')->execute([':id'=>$id]); $_SESSION['flash'][]=['type'=>'success','message'=>'Sviluppo eliminato'];}catch(\Throwable $e){$_SESSION['flash'][]=['type'=>'danger','message'=>'Errore: '.$e->getMessage()];} return $res->withHeader('Location','/admin/developers')->withStatus(302);}    
}

