<?php
declare(strict_types=1);
function correspondenceLoadPinnedFunction(string $path,string $sha,string $name):void
{
    if(!is_file($path)||hash_file('sha256',$path)!==$sha)throw new RuntimeException('exact pinned source required: '.$name);
    $tokens=token_get_all(file_get_contents($path));$function='';$found=false;$depth=0;$opened=false;
    foreach($tokens as$i=>$token){if(!$found&&is_array($token)&&$token[0]===T_FUNCTION){$j=$i+1;while(isset($tokens[$j])&&is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;if(is_array($tokens[$j]??null)&&$tokens[$j][0]===T_STRING&&$tokens[$j][1]===$name)$found=true;}if(!$found)continue;$function.=is_array($token)?$token[1]:$token;if($token==='{'||(is_array($token)&&in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))){$opened=true;$depth++;}elseif($token==='}'){$depth--;if($opened&&$depth===0)break;}}
    if(!$found||!$opened||$depth!==0)throw new RuntimeException('pinned function extraction failed: '.$name);eval($function);
}