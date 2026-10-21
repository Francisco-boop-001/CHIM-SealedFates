<?php
declare(strict_types=1);
require_once __DIR__.'/lib/bootstrap.php';
// Core comm.php has finished its restore prune and init marker before this hook.
correspondenceReleaseInitFence();
require_once __DIR__.'/lib/consumers.php';
if(function_exists('chimRegisterPromptInjection')){
    chimRegisterPromptInjection('character_bottom','correspondence.recipient',function(string $slot,array $context):string{
        $owner=$context['herika_name']??null;if(!is_string($owner)||$owner===''||$owner===($context['narrator_name']??'The Narrator'))return '';
        $parts=[];
        try{$runtime=correspondenceProduction();$runtime['store']->bindRequestClock(is_array($context['game_request']??null)?$context['game_request']:[]);$parts[]=$runtime['service']->prompt($owner);$GLOBALS['CORRESPONDENCE_SOCIAL_STATUS']=correspondenceConsumeReferences(correspondenceEngineRoot(),$runtime['connection'],$runtime['service']->sourceReferences,false,$runtime['service']->knownSourceReferences);}catch(Throwable){correspondenceDiagnostic('context_unavailable');}
        try{$offscreen=correspondenceOffscreenProduction();$offscreen['store']->bindRequestClock(is_array($context['game_request']??null)?$context['game_request']:[]);$parts[]=$offscreen['offscreen']->prompt($owner);}catch(Throwable){correspondenceDiagnostic('offscreen_unavailable');}
        return implode("\n\n",array_values(array_filter($parts,static fn($part)=>is_string($part)&&$part!=='')));
    },120);
} else {
    correspondenceDiagnostic('core_prompt_api_unavailable');
}




