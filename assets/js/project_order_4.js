
// 卡片带毛玻璃样式（backdrop-filter / overflow:hidden），弹窗留在卡片里会被灰色遮罩盖住、点不动；挂到 body 下才正常。
(function () { document.querySelectorAll('.calc-modal').forEach(function (m) { document.body.appendChild(m); }); })();
