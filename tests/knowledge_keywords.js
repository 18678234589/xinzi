'use strict';
const assert = require('assert');
const { findMatches, validLink } = require('../assets/js/knowledge.js');
const links = [
  { keyword: '腾讯云', url: 'https://cloud.tencent.com/', title: '腾讯云' },
  { keyword: '微信开放平台', url: 'https://open.weixin.qq.com/', title: '微信开放平台' },
  { keyword: '开放平台', url: 'https://example.com/', title: '通用平台' },
  { keyword: 'skillhub', url: 'https://skillhub.cn/', title: 'SkillHub' },
  { keyword: 'ai服务器', url: 'https://os.findtoken.net/', title: 'AI 服务器' }
];
assert.deepStrictEqual(findMatches('腾讯云与微信开放平台',links).map(x=>x.text), ['腾讯云','微信开放平台']);
assert.deepStrictEqual(findMatches('SkillHub、AI服务器',links).map(x=>x.text), ['SkillHub','AI服务器']);
assert.strictEqual(findMatches('skillhubfoo',links).length,0);
assert.strictEqual(findMatches('https://skillhub.cn/',links).length,0);
assert.strictEqual(findMatches('平台skillhub',links).length,1);
assert.strictEqual(validLink({keyword:'测试',url:'javascript:alert(1)'}),false);
assert.strictEqual(validLink({keyword:'测试',url:'https://user:pass@example.com/'}),false);
assert.strictEqual(findMatches('售价 6800 成本 1215 提成 670.20',links).length,0);
console.log('All 8 keyword matching checks passed.');
