        </div>
    </div>
</div>

<script><?php /* split: assets/js/shops_upload_1.js */ include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/shops_upload_1.js'; ?></script>

<!-- 订单详情弹窗 -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="fas fa-eye text-info"></i> 订单详情</h5>
                <button type="button" class="close" data-dismiss="modal">&times;</button>
            </div>
            <div class="modal-body" id="detailBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">关闭</button>
            </div>
        </div>
    </div>
</div>

<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php'; ?>
