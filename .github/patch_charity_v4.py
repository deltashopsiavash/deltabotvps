from pathlib import Path
import re

p = Path('charity_campaign.php')
s = p.read_text(encoding='utf-8')

s = s.replace('Managed 7-day school-supplies charity campaign (v3).', 'Managed 7-day school-supplies charity campaign (v4).')

migration = r"        if\(charitySetting\('CHARITY_CONTROL_VERSION',''\)!=='3'\)\{.*?            charitySetSetting\('CHARITY_CONTROL_VERSION','3'\);\n        \}"
replacement = """        if(charitySetting('CHARITY_CONTROL_VERSION','')!=='4'){
            $oldEnabled=charitySetting('CHARITY_ENABLED','0')==='1';
            charitySetSetting('CHARITY_BUTTON_VISIBLE',charitySetting('CHARITY_BUTTON_VISIBLE',$oldEnabled?'1':'0'));
            charitySetSetting('CHARITY_COLLECTING',charitySetting('CHARITY_COLLECTING',$oldEnabled?'1':'0'));
            charitySetSetting('CHARITY_PERCENT',charitySetting('CHARITY_PERCENT','10'));
            charitySetSetting('CHARITY_MAIN_BUTTON_LABEL',charitySetting('CHARITY_MAIN_BUTTON_LABEL','🎒 هزینه جمع‌آوری‌شده'));
            charitySetSetting('CHARITY_MAIN_BUTTON_ICON',charitySetting('CHARITY_MAIN_BUTTON_ICON',''));
            charitySetSetting('CHARITY_REPORT_VISIBLE',charitySetting('CHARITY_REPORT_VISIBLE','0'));
            charitySetSetting('CHARITY_REPORT_BUTTON_LABEL',charitySetting('CHARITY_REPORT_BUTTON_LABEL','📸 گزارش خرید کمک‌ها'));
            charitySetSetting('CHARITY_REPORT_BUTTON_ICON',charitySetting('CHARITY_REPORT_BUTTON_ICON',''));
            charitySetSetting('CHARITY_PAGE_TEMPLATE',charitySetting('CHARITY_PAGE_TEMPLATE',charityDefaultTemplate()));
            charitySetSetting('CHARITY_PAGE_ENTITIES',charitySetting('CHARITY_PAGE_ENTITIES','[]'));

            // v4 is strict: never infer card-to-card from state=approved/payid.
            // Rebuild transaction totals only from payments explicitly tagged by the real card-to-card flow.
            @$connection->query(\"DELETE FROM `charity_contributions` WHERE `source`='transaction'\");
            @$connection->query(\"UPDATE `pays` SET `payment_channel`='legacy_unverified',`payment_confirmed_at`=0 WHERE `payment_channel`='card_to_card' AND `state`='approved' AND `payment_confirmed_at`=`request_date`\");
            @$connection->query(\"UPDATE `pays` SET `payment_channel`='wallet' WHERE `payment_channel`='' AND `state`='paid_with_wallet'\");
            @$connection->query(\"UPDATE `pays` SET `payment_channel`='gateway' WHERE `payment_channel`='' AND `state`='paid'\");
            @$connection->query(\"UPDATE `pays` SET `payment_channel`='other' WHERE `payment_channel`='' AND `state`='approved'\");
            charitySetSetting('CHARITY_CONTROL_VERSION','4');
        }"""
s, n = re.subn(migration, replacement, s, count=1, flags=re.S)
assert n == 1, 'migration block not found'

old = """if (!function_exists('charityExtractButtonPremium')) {
    function charityExtractButtonPremium($text,$entities){if(function_exists('deltaExtractPremiumButtonEmoji'))return deltaExtractPremiumButtonEmoji($text,$entities);return ['text'=>(string)$text,'custom_emoji_id'=>''];}
}"""
new = r"""if (!function_exists('charityEntityValue')) {
    function charityEntityValue($entity,$key,$default=null){
        if(is_array($entity)) return array_key_exists($key,$entity)?$entity[$key]:$default;
        if(is_object($entity)) return isset($entity->$key)?$entity->$key:$default;
        return $default;
    }
}
if (!function_exists('charityUtf16Bytes')) {
    function charityUtf16Bytes($text){
        $text=(string)$text;
        if(function_exists('mb_convert_encoding')) return mb_convert_encoding($text,'UTF-16LE','UTF-8');
        if(function_exists('iconv')) { $v=@iconv('UTF-8','UTF-16LE//IGNORE',$text); return $v===false?'':$v; }
        return '';
    }
}
if (!function_exists('charityFromUtf16Bytes')) {
    function charityFromUtf16Bytes($bytes){
        if(function_exists('mb_convert_encoding')) return mb_convert_encoding($bytes,'UTF-8','UTF-16LE');
        if(function_exists('iconv')) { $v=@iconv('UTF-16LE','UTF-8//IGNORE',$bytes); return $v===false?'':$v; }
        return '';
    }
}
if (!function_exists('charityUtf16Length')) {
    function charityUtf16Length($text){ return intdiv(strlen(charityUtf16Bytes($text)),2); }
}
if (!function_exists('charityUtf16Prefix')) {
    function charityUtf16Prefix($text,$units){ $b=charityUtf16Bytes($text); return charityFromUtf16Bytes(substr($b,0,max(0,(int)$units)*2)); }
}
if (!function_exists('charityUtf16Remove')) {
    function charityUtf16Remove($text,$offset,$length){
        $b=charityUtf16Bytes($text);$a=max(0,(int)$offset)*2;$z=max(0,(int)$length)*2;
        return charityFromUtf16Bytes(substr($b,0,$a).substr($b,$a+$z));
    }
}
if (!function_exists('charityExtractPremiumTextEntities')) {
    function charityExtractPremiumTextEntities($entities){
        $out=[];if(!is_array($entities)&&!($entities instanceof Traversable))return $out;
        foreach($entities as $e){
            if((string)charityEntityValue($e,'type','')!=='custom_emoji')continue;
            $id=(string)charityEntityValue($e,'custom_emoji_id','');if($id==='')continue;
            $out[]=['type'=>'custom_emoji','offset'=>(int)charityEntityValue($e,'offset',0),'length'=>(int)charityEntityValue($e,'length',0),'custom_emoji_id'=>$id];
        }
        return $out;
    }
}
if (!function_exists('charityExtractButtonPremium')) {
    function charityExtractButtonPremium($text,$entities){
        $text=(string)$text;$premium=charityExtractPremiumTextEntities($entities);
        if(!$premium)return ['text'=>$text,'custom_emoji_id'=>''];
        $e=$premium[0];$clean=charityUtf16Remove($text,$e['offset'],$e['length']);
        return ['text'=>trim($clean),'custom_emoji_id'=>(string)$e['custom_emoji_id']];
    }
}"""
assert old in s, 'premium button helper not found'
s = s.replace(old, new, 1)

render_pattern = r"if \(!function_exists\('charityRenderTemplate'\)\) \{.*?\n\}\nif \(!function_exists\('charitySendOrEditWithEntities'\)\) \{.*?\n\}\nif \(!function_exists\('charityRenderCampaign'\)\) \{.*?\n\}"
render_replacement = r"""if (!function_exists('charityTemplateMap')) {
    function charityTemplateMap($c,$t){
        $pct=$c?charityPercentText($c['percent']):charityPercentText(charityConfiguredPercent());
        $time=($c&&charityCampaignIsOpen($c))?charityRemainingText((int)$c['ends_at']-time()):'۰ روز و ۰ ساعت و ۰ دقیقه و ۰ ثانیه';
        return ['{TOTAL}'=>number_format($t['total']??0),'{CARD_TOTAL}'=>number_format($t['transactions']??0),'{DIRECT_TOTAL}'=>number_format($t['direct']??0),'{PERCENT}'=>$pct,'{PARTICIPANTS}'=>number_format($t['participants']??0),'{TIME}'=>$time,'{STATUS}'=>charityCampaignStatusText($c),'{START_TIME}'=>$c?date('Y-m-d H:i',(int)$c['starts_at']):'-','{END_TIME}'=>$c?date('Y-m-d H:i',(int)$c['ends_at']):'-'];
    }
}
if (!function_exists('charityRenderTemplate')) {
    function charityRenderTemplate($c,$t){ return strtr(charityPageTemplate(),charityTemplateMap($c,$t)); }
}
if (!function_exists('charityBuildRenderedCustomEmojiEntities')) {
    function charityBuildRenderedCustomEmojiEntities($template,$map,$saved){
        $out=[];if(!is_array($saved))return $out;
        foreach($saved as $e){
            if((string)charityEntityValue($e,'type','')!=='custom_emoji')continue;
            $id=(string)charityEntityValue($e,'custom_emoji_id','');$off=(int)charityEntityValue($e,'offset',0);$len=(int)charityEntityValue($e,'length',0);
            if($id===''||$len<=0)continue;
            $prefix=charityUtf16Prefix($template,$off);
            $newOffset=charityUtf16Length(strtr($prefix,$map));
            $out[]=['type'=>'custom_emoji','offset'=>$newOffset,'length'=>$len,'custom_emoji_id'=>$id];
        }
        return $out;
    }
}
if (!function_exists('charitySendOrEditWithEntities')) {
    function charitySendOrEditWithEntities($text,$markup,$entities=[]){
        global $chat_id,$message_id,$update,$data;
        if(isset($update->callback_query)&&!empty($update->callback_query->id))bot('answerCallbackQuery',['callback_query_id'=>$update->callback_query->id]);
        $payload=['chat_id'=>$chat_id,'text'=>$text,'reply_markup'=>is_string($markup)?$markup:json_encode($markup,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)];
        if($entities)$payload['entities']=json_encode($entities,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if(!empty($data)&&!empty($message_id)){$p=$payload;$p['message_id']=$message_id;$r=bot('editMessageText',$p);$ok=is_object($r)?!empty($r->ok):(is_array($r)?!empty($r['ok']):$r===true);if($ok)return $r;}
        return bot('sendMessage',$payload);
    }
}
if (!function_exists('charityRenderCampaign')) {
    function charityRenderCampaign(){
        global $buttonValues;
        $c=charityRefreshCampaignState(charityGetCampaign());$tot=$c?charityTotals($c):['total'=>0,'transactions'=>0,'direct'=>0,'participants'=>0];
        $template=charityPageTemplate();$map=charityTemplateMap($c,$tot);$text=strtr($template,$map);$entities=charityBuildRenderedCustomEmojiEntities($template,$map,charityPageEntities());
        $rows=[];if($c&&charityCampaignIsOpen($c)&&charityIsCollecting())$rows[]=[['text'=>'❤️ کمک مستقیم به کمپین','callback_data'=>'charityDonate']];
        if(charityReportVisible()){$b=['text'=>charityReportButtonLabel(),'callback_data'=>'charityProofs'];$i=charityReportButtonIcon();if($i!=='')$b['icon_custom_emoji_id']=$i;$rows[]=[$b];}
        $rows[]=[['text'=>'🔄 بروزرسانی','callback_data'=>'charityCampaign']];$rows[]=[['text'=>$buttonValues['back_to_main']??'🏠 صفحه اصلی','callback_data'=>'mainMenu']];
        charitySendOrEditWithEntities($text,['inline_keyboard'=>$rows],$entities);
    }
}"""
s, n = re.subn(render_pattern, render_replacement, s, count=1, flags=re.S)
assert n == 1, 'render blocks not found'

old_save = "$entities=function_exists('deltaExtractPremiumTextEntities')?deltaExtractPremiumTextEntities($update->message->entities??[]):[];"
assert old_save in s, 'template entity save expression not found'
s = s.replace(old_save, "$entities=charityExtractPremiumTextEntities($update->message->entities??[]);", 1)

p.write_text(s, encoding='utf-8')
