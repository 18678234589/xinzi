
// 文件上传区交互
(function(){
    var area = document.getElementById('uploadArea');
    var file = document.getElementById('excelFile');
    var tip = document.getElementById('uploadTip');
    var csvData = document.getElementById('csvData');
    if (!area || !file) return;
    area.addEventListener('click', function(){ file.click(); });
    file.addEventListener('change', function(){
        if (file.files.length) {
            tip.textContent = file.files[0].name;
            // 选择了文件后清空粘贴框，避免旧数据覆盖新文件
            if (csvData) csvData.value = '';
        }
    });
    ['dragover','dragenter'].forEach(function(ev){ area.addEventListener(ev, function(e){ e.preventDefault(); area.style.borderColor='#28a745'; }); });
    ['dragleave','drop'].forEach(function(ev){ area.addEventListener(ev, function(e){ e.preventDefault(); area.style.borderColor=''; }); });
    area.addEventListener('drop', function(e){
        if (e.dataTransfer.files.length){
            file.files = e.dataTransfer.files;
            tip.textContent = file.files[0].name;
            if (csvData) csvData.value = '';
        }
    });
})();
// 提交前校验：若同时有文件和粘贴数据，提示优先使用文件
document.getElementById('uploadForm').addEventListener('submit', function(e){
    var file = document.getElementById('excelFile');
    var csvData = document.getElementById('csvData');
    if (file.files.length && csvData.value.trim() !== '') {
        if (!confirm('检测到同时有上传文件和粘贴数据，将优先使用上传文件。粘贴框的数据将被忽略，是否继续？')) {
            e.preventDefault();
        }
    }
});
function batchDelete(){
    var n = document.querySelectorAll('.row-chk:checked').length;
    if (n === 0) { alert('请先勾选要删除的记录'); return; }
    if (!confirm('确定删除选中的 ' + n + ' 条考勤记录？')) return;
    document.getElementById('delForm').submit();
}
function delOne(id){
    if (!confirm('删除该考勤记录？')) return;
    var f = document.createElement('form');
    f.method = 'post';
    f.style.display = 'none';
    f.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' + id + '"><input type="hidden" name="year" value="<?php echo $year; ?>"><input type="hidden" name="month" value="<?php echo $month; ?>">';
    document.body.appendChild(f);
    f.submit();
}
