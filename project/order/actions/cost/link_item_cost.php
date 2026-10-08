<?php

            if (!$finance && $actor['role'] !== 'technical') throw new RuntimeException('仅本单技术或财务可确认商品成本');
            if (!$canEdit) throw new RuntimeException('订单已锁定，不能修改商品成本');
            poi_link_cost($id, (int)($_POST['item_id'] ?? 0), (int)($_POST['cost_id'] ?? 0), $actor);
        