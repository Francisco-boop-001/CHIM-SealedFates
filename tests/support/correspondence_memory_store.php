<?php
declare(strict_types=1);
final class CorrespondenceMemoryStore implements CorrespondenceStore
{
    public array $events=[],$docs=[],$operations=[],$commands=[],$notes=[],$documentLookups=[];
    public int $writerTransactionCalls=0,$readTransactionCalls=0,$actorRowLockCalls=0,$transactionMemoBuilds=0;
    public ?string $executor='Lydia';
    public array $clock=['ts'=>100,'gamets'=>100];
    public array $actors=[900001=>['id'=>900001,'npc_name'=>'Camilla','refid'=>'00013488'],900002=>['id'=>900002,'npc_name'=>'Jarl Balgruuf','refid'=>'0001A677']];
    public ?Closure $beforeDocuments=null;
    public ?Closure $afterReadTransaction=null;
    public ?Closure $beforePrepareNote=null;
    private bool $active=false;
    private bool $readOnly=false;
    private array $transactionMemo=[];
    private ?array $actorCatalogCache=null;
    private function runTransaction(callable $work,bool $readOnly):mixed
    {
        if($this->active)return $work();
        $this->transactionMemo=[];$this->actorCatalogCache=null;
        $before=[$this->events,$this->docs,$this->operations,$this->commands,$this->notes];
        $this->active=true;$this->readOnly=$readOnly;
        try{return $work();}
        catch(Throwable $e){[$this->events,$this->docs,$this->operations,$this->commands,$this->notes]=$before;throw $e;}
        finally{$this->active=false;$this->readOnly=false;$this->transactionMemo=[];$this->actorCatalogCache=null;}
    }
    public function transaction(callable $work): mixed { $this->writerTransactionCalls++;return $this->runTransaction($work,false); }
    public function readTransaction(callable $work): mixed
    {
        $this->readTransactionCalls++;
        $result=$this->runTransaction($work,true);
        $callback=$this->afterReadTransaction;$this->afterReadTransaction=null;
        if($callback!==null)$callback($this);
        return $result;
    }
    public function scope():array{return ['playthrough_id'=>'unprofiled']+$this->clock;}
    public function actors():array
    {
        if($this->active&&$this->actorCatalogCache!==null)return $this->actorCatalogCache;
        $rows=array_values($this->actors);
        if($this->active)$this->actorCatalogCache=$rows;
        return $rows;
    }
    public function transactionMemo(string $key,callable $build):mixed
    {
        if(!$this->active){$this->transactionMemoBuilds++;return $build();}
        if(!array_key_exists($key,$this->transactionMemo)){$this->transactionMemoBuilds++;$this->transactionMemo[$key]=$build();}
        return $this->transactionMemo[$key];
    }
    public function lockActorRows(array $npcIds,bool $includePlayer=false):array
    {
        if(!$this->active||$this->readOnly)throw new LogicException('effect_identity_lock_requires_writer');
        $this->actorRowLockCalls++;
        $rows=[];
        foreach(array_unique($npcIds)as$id)if(is_int($id)&&isset($this->actors[$id]))$rows[$id]=$this->actors[$id];
        return ['actors'=>$rows,'player_name'=>$includePlayer?'Dragonborn':null];
    }
    public function player():string{return 'Dragonborn';}
    public function findRequest(string $scope,string $request):?array{foreach($this->docs as$d)if($d['playthrough_id']===$scope&&$d['request_id']===$request)return $d;return null;}
    public function loadDocument(string $id):?array{return $this->docs[$id]??null;}
    public function saveDocument(array $document):void{$this->docs[$document['document_id']]=$document;}
    public function documents(string $scope, ?int $recipientId=null):array
    {
        $rows=array_values(array_filter($this->docs,fn($d)=>$d['playthrough_id']===$scope&&($recipientId===null||(($d['recipient']['kind']??null)==='npc'&&($d['recipient']['id']??null)===$recipientId))));
        $this->documentLookups[]=['scope'=>$scope,'recipient_id'=>$recipientId];
        $callback=$this->beforeDocuments;$this->beforeDocuments=null;if($callback!==null)$callback($this);
        return $rows;
    }
    public function loadOperation(string $id):?array{return $this->operations[$id]??null;}
    public function saveOperation(array $operation):void{$this->operations[$operation['operation_id']]=$operation;}
    public function appendEvent(string $type,string $data,string $people,string $sess):array{$id=count($this->events)+1;return $this->events[$id]=['rowid'=>$id,'type'=>$type,'data'=>$data,'people'=>$people,'sess'=>$sess,'ts'=>$this->clock['ts'],'gamets'=>$this->clock['gamets'],'localts'=>100];}
    public function eventMatches(array $event):bool{return ($this->events[$event['rowid']]??null)===$event;}
    public function managedExecutor():?string{return $this->executor;}
    public function prepareNote(string $title,string $body):void{if($this->beforePrepareNote!==null)($this->beforePrepareNote)($title,$body);$this->notes[]=['title'=>$title,'text'=>$body];$this->commands[]=['actor'=>'rolemaster','action'=>'rolecommand|generateLetter@'.$title];}
    public function queueCommand(string $actor,string $action):void{$this->commands[]=compact('actor','action');}
    public function physicalActor(array $actor):array{return ['refid'=>$actor['kind']==='player'?20:(int)hexdec($this->actors[$actor['id']]['refid']),'plugin'=>'Skyrim.esm','local_id'=>$actor['kind']==='player'?20:(int)hexdec($this->actors[$actor['id']]['refid'])];}
    public function physicalItem(array $item):array{return ['kind'=>$item['kind'],'form_id'=>15,'plugin'=>'Skyrim.esm','local_id'=>15,'quantity'=>$item['quantity']];}
}

