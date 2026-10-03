<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/ProjectKnowledge.php';
$checks = 0;
function kbc_check($actual,$expected) { global $checks; $checks++; if ($actual !== $expected) throw new RuntimeException('Category assertion failed: ' . json_encode($actual)); }
function kbc_reject($callback) { $rejected=false; try { $callback(); } catch (RuntimeException $e) { $rejected=true; } kbc_check($rejected,true); }
kbc_check(pk_category_name('  设计灵感  '),'设计灵感');
kbc_check(pk_category_name('AI   与创作'),'AI 与创作');
kbc_check(pk_category_name('　设计灵感　'),'设计灵感');
kbc_check(pk_category_input(['category'=>'__new__','new_category'=>'售后经验'],'常用工具'),'售后经验');
kbc_check(pk_category_input(['category'=>'常用工具','new_category'=>'忽略的旧输入'],'经验与方法'),'常用工具');
kbc_check(pk_category_input([],'经验与方法'),'经验与方法');
kbc_reject(function(){pk_category_input(['category'=>'__new__'],'常用工具');});
kbc_reject(function(){pk_category_name(' ');});
kbc_reject(function(){pk_category_name(['not a string']);});
kbc_reject(function(){pk_category_name('__new__');});
kbc_reject(function(){pk_category_name('<script>');});
kbc_reject(function(){pk_category_name("\0bad");});
kbc_reject(function(){pk_category_name(str_repeat('字',61));});
kbc_check(mb_strlen(pk_category_name(str_repeat('字',60))),60);
$payload = base64_encode(json_encode(['category'=>'__new__','new_category'=>'设计灵感'],JSON_UNESCAPED_UNICODE));
$decoded = pk_content_post(['kb_parts_count'=>1,'kb_part_0'=>$payload,'kb_content_sha256'=>hash('sha256',$payload)],['category','new_category']);
kbc_check(pk_category_input($decoded,'常用工具'),'设计灵感');
echo "knowledge category unit checks: $checks passed\n";
