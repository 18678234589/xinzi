                <p class="mb-3">选择核验标准，系统将通过订单编号匹配店铺订单中的状态和金额，不符合标准的订单将标记为异常：</p>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <div class="card border-success cursor-pointer" onclick="doVerify('shipped')" style="cursor:pointer">
                            <div class="card-body text-center py-3">
                                <i class="fas fa-truck text-success fa-2x mb-2"></i>
                                <h6 class="mb-1">已发货</h6>
                                <small class="text-muted">状态为「已发货」或「交易成功」为正常<br>其他状态标记为异常</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <div class="card border-primary cursor-pointer" onclick="doVerify('success')" style="cursor:pointer">
                            <div class="card-body text-center py-3">
                                <i class="fas fa-check-circle text-primary fa-2x mb-2"></i>
                                <h6 class="mb-1">交易成功</h6>
                                <small class="text-muted">状态为「交易成功」为正常<br>其他状态标记为异常</small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">取消</button>
            </div>
        </div>
    </div>
</div>

<?php include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php'; ?>
