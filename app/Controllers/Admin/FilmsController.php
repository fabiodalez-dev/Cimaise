<?php
declare(strict_types=1);

namespace App\Controllers\Admin;
use App\Controllers\BaseController;
use App\Support\Database;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class FilmsController extends BaseController
{
    public function __construct(private Database $db, private Twig $view) 
    {
        parent::__construct();
    }

    public function index(Request $request, Response $response): Response
    {
        $page=max(1,(int)($request->getQueryParams()['page']??1)); $per=10; $off=($page-1)*$per; $pdo=$this->db->pdo();
        $total=(int)$pdo->query('SELECT COUNT(*) FROM films')->fetchColumn();
        $st=$pdo->prepare('SELECT id, brand, name, iso, format, type FROM films ORDER BY brand, name LIMIT :l OFFSET :o');
        $st->bindValue(':l',$per,\PDO::PARAM_INT); $st->bindValue(':o',$off,\PDO::PARAM_INT); $st->execute();
        return $this->view->render($response,'admin/films/index.twig',['items'=>$st->fetchAll(),'page'=>$page,'pages'=>(int)ceil(max(0,$total)/$per)]);
    }
    public function create(Request $r, Response $res): Response {return $this->view->render($res,'admin/films/create.twig',['csrf'=>$_SESSION['csrf']??'']);}
    public function store(Request $r, Response $res): Response{
        $d=(array)$r->getParsedBody(); $brand=trim((string)($d['brand']??'')); $name=trim((string)($d['name']??''));
        $iso=$d['iso']!==''?(int)$d['iso']:null; $format=(string)($d['format']??'35mm'); $type=(string)($d['type']??'bw');
        if($brand===''||$name===''){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Brand e Nome obbligatori']; return $res->withHeader('Location',$this->basePath . '/admin/films/create')->withStatus(302);}        
        try{ $this->db->pdo()->prepare('INSERT INTO films(brand, name, iso, format, type) VALUES(?,?,?,?,?)')->execute([$brand,$name,$iso,$format,$type]); $_SESSION['flash'][]=['type'=>'success','message'=>'Pellicola creata']; }
        catch(\Throwable $e){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Errore: '.$e->getMessage()]; return $res->withHeader('Location',$this->basePath . '/admin/films/create')->withStatus(302);}        
        return $res->withHeader('Location',$this->basePath . '/admin/films')->withStatus(302);
    }
    public function edit(Request $r, Response $res, array $args): Response{ $id=(int)($args['id']??0); $st=$this->db->pdo()->prepare('SELECT * FROM films WHERE id=:id'); $st->execute([':id'=>$id]); $it=$st->fetch(); if(!$it){return $res->withStatus(404);} return $this->view->render($res,'admin/films/edit.twig',['item'=>$it,'csrf'=>$_SESSION['csrf']??'']);}
    public function update(Request $r, Response $res, array $args): Response{
        $id=(int)($args['id']??0); $d=(array)$r->getParsedBody(); $brand=trim((string)($d['brand']??'')); $name=trim((string)($d['name']??''));
        $iso=$d['iso']!==''?(int)$d['iso']:null; $format=(string)($d['format']??'35mm'); $type=(string)($d['type']??'bw');
        if($brand===''||$name===''){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Brand e Nome obbligatori']; return $res->withHeader('Location',$this->basePath . '/admin/films/'.$id.'/edit')->withStatus(302);}        
        try{ $this->db->pdo()->prepare('UPDATE films SET brand=?, name=?, iso=?, format=?, type=? WHERE id=?')->execute([$brand,$name,$iso,$format,$type,$id]); $_SESSION['flash'][]=['type'=>'success','message'=>'Pellicola aggiornata']; }
        catch(\Throwable $e){ $_SESSION['flash'][]=['type'=>'danger','message'=>'Errore: '.$e->getMessage()]; }
        return $res->withHeader('Location',$this->basePath . '/admin/films')->withStatus(302);
    }
    public function delete(Request $r, Response $res, array $args): Response{ $id=(int)($args['id']??0); try{$this->db->pdo()->prepare('DELETE FROM films WHERE id=:id')->execute([':id'=>$id]); $_SESSION['flash'][]=['type'=>'success','message'=>'Pellicola eliminata'];}catch(\Throwable $e){$_SESSION['flash'][]=['type'=>'danger','message'=>'Errore: '.$e->getMessage()];} return $res->withHeader('Location',$this->basePath . '/admin/films')->withStatus(302);}    
}

