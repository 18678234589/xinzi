# 代码地图

由 `php tools/gen_code_map.php` 生成。先搜索函数名，再按行号读取目标片段。

## abnormal/employee.php (248 行, 11.7 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## abnormal/index.php (315 行, 14.6 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## abnormal/shop.php (250 行, 11.9 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## algorithms/default.php (54 行, 1.8 KB)


## attendance/index.php (171 行, 8.3 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## attendance/month.php (622 行, 38.2 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## attendance/year.php (89 行, 3.9 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## captcha.php (47 行, 1.4 KB)


## check_order_no.php (32 行, 1.2 KB)

- 加载：`require_once __DIR__ . '/includes/auth.php';`

## classes/SimpleXLSX.php (333 行, 12.2 KB)

- `SimpleXLSX` L12–262
- `SimpleXLSX::parse` L25–37
- `SimpleXLSX::sheetNames` L42–54
- `SimpleXLSX::parseAll` L60–75
- `SimpleXLSX::loadSharedStrings` L80–100
- `SimpleXLSX::loadWorkbook` L106–183
- `SimpleXLSX::readSheet` L188–247
- `SimpleXLSX::colToIndex` L252–261
- `SimpleXLSXZipReader` L268–332
- `SimpleXLSXZipReader::open` L273–308
- `SimpleXLSXZipReader::getFromName` L310–322
- `SimpleXLSXZipReader::close` L324–328
- `SimpleXLSXZipReader::u16` L330–330
- `SimpleXLSXZipReader::u32` L331–331

## departments/index.php (189 行, 8.2 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## employees/algorithm.php (884 行, 49.4 KB)

- `renderModuleForm` L366–466
- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`
- 加载：`include $deptConfigFile;`
- 加载：`include $deptFeeFile;`
- 加载：`include __DIR__ . '/../includes/header.php';`

## employees/index.php (242 行, 12.6 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## includes/ProjectAiFallback.php (150 行, 9.1 KB)

- `ps_ai_signature` L9–13
- `ps_ai_touch` L16–19
- `ps_ai_touched` L21–24
- `ps_ai_mark_applied` L26–32
- `ps_ai_solution_find` L35–49
- `ps_ai_solution_save` L51–58
- `ps_ai_log_error` L61–64
- `ps_ai_labels` L66–69
- `ps_ai_import_columns` L75–116
- `ps_ai_resolve_values` L122–149
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`

## includes/ProjectApiSettings.php (166 行, 9.6 KB)

- `pas_providers` L10–20
- `pas_store` L22–26
- `pas_get` L29–35
- `pas_is_set` L37–40
- `pas_mask` L43–50
- `pas_save` L53–68
- `pas_http` L70–81
- `pas_tencent_call` L84–102
- `pas_qiniu_b64` L105–105
- `pas_qiniu_call` L113–125
- `pas_qiniu_qbox_call` L128–136
- `pas_test` L139–165
- 加载：`require_once __DIR__ . '/ProjectSystem.php';`
- 加载：`require_once __DIR__ . '/ProjectVault.php';`

## includes/ProjectAutoReview.php (270 行, 19.8 KB)

- `pa_storage_available` L10–18
- `pa_sources` L20–71
- `pa_context` L73–121
- `pa_evidence` L123–145
- `pa_record` L147–161
- `pa_retryable_database_error` L164–167
- `pa_check_order` L169–181
- `pa_check_order_once` L183–212
- `pa_batch` L214–244
- `pa_view` L246–256
- `pa_after_save` L259–269
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderSplit.php';`
- 加载：`require_once __DIR__ . '/ProjectSiteProjects.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundTrash.php';`
- 加载：`require_once __DIR__ . '/ProjectAutoReviewMath.php';`
- 加载：`require_once __DIR__ . '/ProjectReviewPolicy.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderItems.php';`

## includes/ProjectAutoReviewMath.php (202 行, 17.5 KB)

- `pa_cents` L5–13
- `pa_payment_evidence` L15–69
- `pa_evaluate` L71–189
- `pa_state_meta` L191–201

## includes/ProjectBusiness.php (377 行, 30.5 KB)

- `ps_business_catalog` L8–46
- `ps_business_account_products` L49–55
- `ps_business_fallback` L57–63
- `ps_business_normalize` L65–69
- `ps_is_website_order` L71–74
- `ps_business_requires_technical` L77–80
- `ps_business_service_fee_rate` L82–85
- `ps_business_order_kinds` L87–90
- `ps_order_kind_from_role` L93–97
- `ps_actor_businesses` L99–119
- `ps_business_choice` L121–127
- `ps_require_business` L129–135
- `ps_active_employee_for_business` L137–144
- `ps_business_details` L146–159
- `ps_save_business_details` L161–165
- `ps_order_kind_valid` L167–173
- `ps_business_import_columns` L179–275
- `ps_import_role_extras` L279–298
- `ps_business_import_headers` L301–306
- `ps_business_import_headers_base` L308–329
- `ps_business_import_map` L332–345
- `ps_import_delivery_status` L350–362
- `ps_business_people_labels` L364–376
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`

## includes/ProjectCostRequests.php (228 行, 13.4 KB)

- `pcr_ensure` L14–48
- `pcr_can_tag` L51–54
- `pcr_can_review` L57–63
- `pcr_tag_map` L65–71
- `pcr_templates` L77–97
- `pcr_set_tags` L99–114
- `pcr_bulk_tag` L117–136
- `pcr_message` L138–144
- `pcr_submit` L146–180
- `pcr_requests` L182–188
- `pcr_my_requests` L190–196
- `pcr_handle` L198–217
- `pcr_pending_count` L220–227
- 加载：`require_once __DIR__ . '/ProjectKnowledgeSkills.php';`
- 加载：`require_once __DIR__ . '/dup_feedback.php';`

## includes/ProjectDepartmentImport.php (72 行, 3.4 KB)

- `ps_department_import_allowed` L5–14
- `ps_department_import_people` L16–31
- `ps_department_import_is_order` L33–38
- `ps_department_import_record` L40–48
- `ps_department_import_uploader_access` L50–55
- `ps_department_renewal_rates` L58–71
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectMonthly.php';`

## includes/ProjectExpectedSettlement.php (140 行, 9.1 KB)

- `ps_expected_snapshot_rows` L7–56
- `ps_expected_rollup` L59–83
- `ps_expected_month` L85–128
- `ps_partner_expected_income` L130–139
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundTrash.php';`

## includes/ProjectGovernance.php (529 行, 32.4 KB)

- `pg_member` L7–13
- `pg_require_member` L15–21
- `pg_require_contribution_editor` L27–38
- `pg_actor_columns` L41–45
- `pg_uploaded_files` L48–57
- `pg_kind_label` L59–62
- `pg_review_label` L64–67
- `pg_can_review` L69–75
- `pg_validate_date` L77–87
- `pg_idea_policy` L89–100
- `pg_holiday_dates` L103–112
- `pg_idea_deadline` L115–126
- `pg_message` L129–134
- `pg_workdays_between` L137–145
- `pg_sync_reminders` L156–192
- `pg_oversight_policy` L195–201
- `pg_oversight_tasks` L204–215
- `pg_oversight_done_by` L221–229
- `pg_committee_unit_plan` L234–241
- `pg_oversight_done` L244–247
- `pg_committee_team_units` L254–267
- `pg_committee_members` L269–272
- `pg_sync_oversight_penalties` L275–296
- `pg_unread_messages` L299–308
- `pg_workdays_in` L311–319
- `pg_chair_pool_amount` L322–330
- `pg_chair_term` L339–367
- `pg_sync_idea_penalties` L370–393
- `pg_idea_window_status` L399–416
- `pg_election_schedule` L419–423
- `pg_sync_election_notices` L425–440
- `pg_quarter_start` L442–447
- `pg_active_rotation` L449–455
- `pg_chair_pool` L457–485
- `pg_private_dir` L488–491
- `pg_store_evidence` L494–503
- `pg_save_evidence_file` L506–528
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`

## includes/ProjectGovernanceRuleAccess.php (22 行, 1 KB)

- `pgr_access` L3–16
- `pgr_rule_visible` L18–21

## includes/ProjectImportClassification.php (47 行, 2.4 KB)

- `ps_import_website_business` L3–13
- `ps_import_website_people_roles` L15–46

## includes/ProjectImportResult.php (62 行, 3.8 KB)

- `ps_import_upload_actor` L3–13
- `ps_import_result_get` L15–20
- `ps_import_order_visible` L22–28
- `ps_import_result_save` L30–53
- `ps_import_numeric_summary` L56–61

## includes/ProjectImportUndo.php (149 行, 9.6 KB)

- `pu_blocking_tables` L9–22
- `pu_owned_tables` L25–29
- `pu_file_for_actor` L31–39
- `pu_ids_used_by_other_files` L42–51
- `pu_plan` L54–97
- `pu_execute` L100–148
- 加载：`require_once __DIR__ . '/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/ProjectImportResult.php';`

## includes/ProjectIntake.php (840 行, 57 KB)

- `ps_intake_templates` L6–16
- `ps_intake_template` L18–25
- `ps_intake_program_suggestion` L28–47
- `ps_template_cost_amount` L53–64
- `ps_template_cost_status` L67–71
- `ps_intake_add_template_cost` L73–88
- `ps_intake_save_resources` L90–94
- `ps_intake_confirm_resources` L100–126
- `ps_employee_default_role` L129–134
- `ps_intake_participants` L136–157
- `ps_intake_domain_suggestion` L159–173
- `ps_order_no_canonical` L176–182
- `ps_order_no_resolve` L185–200
- `ps_import_group_taken` L203–208
- `ps_trademark_technical_role_open` L214–221
- `ps_trademark_add_technical` L224–233
- `ps_trademark_fix_row` L239–267
- `ps_import_date` L269–294
- `ps_import_names` L300–326
- `ps_import_split_joined_names` L329–345
- `ps_import_names_lenient` L351–364
- `ps_import_autofill_details` L370–381
- `ps_import_fix_guide` L387–407
- `ps_import_followup_rows` L413–426
- `ps_import_followup_save` L429–450
- `ps_import_followup_get` L453–461
- `ps_business_import_example_row` L464–480
- `ps_import_row_is_example` L483–487
- `ps_import_row_is_data` L490–494
- `ps_import_headerless_map` L500–543
- `ps_import_employee_index` L546–556
- `ps_import_domain_mode` L558–564
- `ps_import_kind_preference` L566–575
- `ps_import_kind_preference_save` L577–582
- `ps_import_business_signature` L585–588
- `ps_import_business_detect` L591–642
- `ps_import_business_preference_save` L645–651
- `ps_import_file_store` L656–666
- `ps_import_original_name` L669–674
- `ps_import_file_get` L677–691
- `ps_import_file_delete` L694–717
- `ps_import_file_sheets` L720–727
- `ps_import_file_parse` L729–743
- `ps_xlsx_sheets` L750–829
- `ps_import_file_mark` L831–839
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderItems.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`

## includes/ProjectJointIntake.php (15 行, 0.7 KB)

- `ps_joint_customer_ids` L3–14

## includes/ProjectKnowledge.php (345 行, 21.6 KB)

- `pk_ready` L5–11
- `pk_is_super` L13–19
- `pk_departments` L21–40
- `pk_context` L42–51
- `pk_owner` L53–56
- `pk_readable` L58–68
- `pk_editable` L70–75
- `pk_article` L77–84
- `pk_access_sql` L87–102
- `pk_limit` L104–109
- `pk_content_post` L112–137
- `pk_url` L140–155
- `pk_keywords` L157–169
- `pk_keyword_conflicts` L171–179
- `pk_links` L181–185
- `pk_save_link` L187–223
- `pk_link_state` L225–238
- `pk_article_input` L240–259
- `pk_revision` L261–264
- `pk_save_article` L266–292
- `pk_stage_document` L295–327
- `pk_tabs` L329–337
- `pk_hero` L339–342
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectKnowledgeCategories.php';`

## includes/ProjectKnowledgeCategories.php (61 行, 4.3 KB)

- `pk_category_name` L3–11
- `pk_category_input` L13–17
- `pk_categories` L19–23
- `pk_create_category` L26–44
- `pk_category_picker` L46–60

## includes/ProjectKnowledgeChatImport.php (148 行, 9.3 KB)

- `pks_chat_header` L12–18
- `pks_chat_split_blocks` L20–27
- `pks_chat_normalize` L30–64
- `pks_chat_import_local` L66–79
- `pks_chat_import_parse` L85–128
- `pks_chat_import_save` L131–147
- 加载：`require_once __DIR__ . '/ProjectKnowledgeSkills.php';`

## includes/ProjectKnowledgeConnectors.php (178 行, 11.7 KB)

- `pk_provider` L6–10
- `pk_external_id` L12–17
- `pk_document_reference` L19–34
- `pk_http` L36–63
- `pk_integration` L65–72
- `pk_save_integration` L74–92
- `pk_access_token` L94–105
- `pk_external_list` L107–131
- `pk_block_text` L134–143
- `pk_fetch_document` L145–177
- 加载：`require_once __DIR__ . '/ProjectKnowledge.php';`
- 加载：`require_once __DIR__ . '/ProjectVault.php';`

## includes/ProjectKnowledgeSkills.php (457 行, 24.9 KB)

- `pks_asset` L16–20
- `pks_json_body` L23–28
- `pks_json_reply` L30–36
- `pks_post_script` L39–57
- `pks_ensure` L59–89
- `pks_auto_roles` L92–105
- `pks_actor_roles` L111–120
- `pks_actor_role` L123–127
- `pks_actor_businesses` L129–134
- `pks_is_editor` L137–140
- `pks_actor_name` L142–151
- `pks_mask_pii` L154–162
- `pks_clean_roles` L164–173
- `pks_text` L175–182
- `pks_input` L184–206
- `pks_visible_sql` L208–214
- `pks_row` L216–225
- `pks_can_edit` L227–231
- `pks_save` L233–268
- `pks_set_status` L270–281
- `pks_delete` L283–288
- `pks_list` L294–325
- `pks_counts` L328–335
- `pks_rolebar` L338–352
- `pks_role_labels` L354–358
- `pks_parse_chat` L361–373
- `pks_lines` L375–378
- `pks_ai_record` L381–402
- `pks_ai_records` L405–415
- `pks_ai_search` L418–433
- `pks_ai_fill` L436–456
- 加载：`require_once __DIR__ . '/ProjectKnowledge.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`

## includes/ProjectMonthly.php (913 行, 75.4 KB)

- `ps_monthly_types` L30–49
- `ps_monthly_metrics` L51–54
- `ps_monthly_rules_for` L56–64
- `ps_monthly_scope_businesses` L66–70
- `ps_monthly_snapshot_matches` L72–80
- `ps_monthly_snapshots` L82–98
- `ps_monthly_snapshot_values` L104–113
- `ps_monthly_attendance` L116–128
- `ps_monthly_prorate` L131–138
- `ps_attendance_suggestion` L144–152
- `ps_monthly_inputs` L154–161
- `ps_monthly_pick_tier` L163–169
- `ps_sales_package_calc` L178–201
- `ps_legacy_sheet_orders` L210–299
- `ps_monthly_results` L305–720
- `ps_monthly_freeze` L722–729
- `ps_monthly_params_from_input` L732–821
- `ps_monthly_presets` L827–870
- `ps_monthly_apply_presets` L872–912
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/SalaryCalculator.php';`
- 加载：`require_once __DIR__ . '/ProjectReviewPolicy.php';`
- 加载：`require_once __DIR__ . '/functions.php';`
- 加载：`require_once __DIR__ . '/ProjectReviewPolicy.php';`

## includes/ProjectOrderFix.php (168 行, 10.6 KB)

- `pof_ensure` L12–36
- `pof_money` L38–38
- `pof_fields` L41–50
- `pof_split_hint` L56–68
- `pof_actor_name` L70–74
- `pof_apply_changes` L77–91
- `pof_submit` L94–128
- `pof_requests` L130–135
- `pof_pending_count` L137–141
- `pof_handle` L143–167
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`

## includes/ProjectOrderItems.php (232 行, 14.9 KB)

- `poi_ensure` L3–22
- `poi_name` L24–28
- `poi_money` L30–34
- `poi_template` L37–58
- `poi_from_row` L61–100
- `poi_keyed` L102–114
- `poi_link_cost` L116–125
- `poi_items` L127–133
- `poi_save` L136–171
- `poi_sync_costs` L174–208
- `poi_linked_template` L210–214
- `poi_select_program` L217–231

## includes/ProjectOrderNo.php (51 行, 3 KB)

- `pon_is_internal` L8–11
- `pon_rename` L17–50
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`

## includes/ProjectOrderSource.php (230 行, 15.6 KB)

- `ps_source_record` L4–8
- `ps_payment_reference_order_no` L11–16
- `ps_customer_intake_conflicts` L18–33
- `ps_customer_intake_conflict_detail` L36–47
- `ps_save_customer_intake` L50–100
- `ps_source_nickname` L102–112
- `ps_shop_status_rank` L119–129
- `ps_sync_project_status_latest` L132–143
- `ps_sync_project_from_shop_order` L145–178
- `ps_sync_existing_shop_order` L180–194
- `ps_shop_order_lookup` L200–229
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`

## includes/ProjectOrderSplit.php (81 行, 4.1 KB)

- `pos_ensure` L9–27
- `pos_child_order_no` L30–33
- `pos_parent_of` L36–42
- `pos_children_of` L45–51
- `pos_link` L53–58
- `pos_evidence_order_no` L61–65
- `pos_summary_text` L68–80
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`

## includes/ProjectPartnerDashboard.php (192 行, 14.5 KB)

- `ps_partner_business_bucket` L6–10
- `ps_partner_orders_url` L12–19
- `ps_partner_list_employee_id` L22–25
- `ps_partner_orders` L28–41
- `ps_partner_month_bounds` L43–48
- `ps_partner_summary` L50–87
- `ps_partner_fallback_guidance` L89–126
- `ps_partner_ai_input` L128–135
- `ps_partner_ai_text` L137–146
- `ps_partner_ai_validate` L148–166
- `ps_partner_ai_generate` L168–191
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundTrash.php';`

## includes/ProjectPresets.php (184 行, 20.8 KB)

- `ps_preset_cost_templates` L10–49
- `ps_preset_rules` L56–128
- `ps_preset_template_exists` L130–137
- `ps_preset_rule_exists` L139–154
- `ps_apply_preset_templates` L157–168
- `ps_apply_preset_rules` L171–183
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`

## includes/ProjectRefundClawback.php (81 行, 6.3 KB)

- `prc_commission_rows` L7–14
- `prc_money` L16–16
- `prc_rows_text` L18–25
- `prc_clawback_for_refund` L31–60
- `prc_backfill` L63–80
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectExpectedSettlement.php';`

## includes/ProjectRefundImport.php (431 行, 33.1 KB)

- `ps_refund_settled_through` L8–12
- `ps_refund_live_sql` L15–21
- `ps_refund_is_history` L23–27
- `ps_refund_website_business` L29–32
- `ps_refund_after_sales` L34–40
- `ps_refund_order_access` L42–48
- `ps_refund_can_view_order` L50–56
- `ps_refund_columns` L58–72
- `ps_refund_header_map` L74–84
- `ps_refund_method` L86–95
- `ps_refund_fingerprint` L97–103
- `ps_refund_order_tokens` L107–115
- `ps_refund_resolve_order` L117–144
- `ps_refund_reconcile_pending` L147–160
- `ps_refund_amount` L162–167
- `ps_refund_preview_row` L170–229
- `ps_refund_parse_file` L231–258
- `ps_refund_commit_rows` L260–310
- `ps_refund_review` L313–368
- `ps_refund_system_actor` L375–375
- `ps_refund_auto_apply` L377–408
- `ps_refund_pending_breakdown` L411–430
- 加载：`require_once __DIR__ . '/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectAiFallback.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundTrash.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundClawback.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundMatch.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`

## includes/ProjectRefundMatch.php (188 行, 14.3 KB)

- `prm_candidates` L5–12
- `prm_base` L14–17
- `prm_hint` L20–27
- `prm_pending_unmatched` L29–44
- `prm_resolve` L47–94
- `prm_auto_nickname` L97–112
- `prm_looks_like_order_no` L115–120
- `prm_waiting_upload` L123–141
- `prm_shop_refund_sync` L148–187
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`

## includes/ProjectRefundTrash.php (75 行, 4.3 KB)

- `prt_storage_available` L3–11
- `prt_active_sql` L13–17
- `prt_can_trash` L19–22
- `prt_deleted_duplicate` L24–32
- `prt_change` L34–66
- `prt_after_change` L69–74
- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`

## includes/ProjectRenewalImport.php (70 行, 6.4 KB)

- `pr_import_fields` L4–30
- `pr_import_apply` L31–69
- 加载：`require_once __DIR__.'/ProjectRenewals.php';`

## includes/ProjectRenewalMath.php (75 行, 4 KB)

- `pr_date` L3–10
- `pr_today` L11–11
- `pr_default_expiry` L12–20
- `pr_days` L21–25
- `pr_reminder_day` L26–31
- `pr_phone` L32–40
- `pr_type_labels` L41–41
- `pr_types_for` L43–49
- `pr_owner_labels` L50–50
- `pr_default_type` L51–51
- `pr_import_label_type` L53–64
- `pr_policy_scope` L65–74

## includes/ProjectRenewalSms.php (121 行, 11.7 KB)

- `pr_sms_config` L4–7
- `pr_sms_map` L8–14
- `pr_sms_save_config` L15–35
- `pr_sms_request` L37–51
- `pr_sms_response` L52–59
- `pr_sms_send` L60–68
- `pr_sms_run` L70–120
- 加载：`require_once __DIR__ . '/ProjectRenewals.php';`

## includes/ProjectRenewals.php (150 行, 12.4 KB)

- `pr_ready` L6–12
- `pr_ensure_owner` L14–21
- `pr_set_owner` L27–42
- `pr_scope` L44–50
- `pr_require` L51–55
- `pr_is_super` L56–61
- `pr_order_where` L63–73
- `pr_order` L74–81
- `pr_seed` L82–90
- `pr_item` L91–96
- `pr_save` L97–143
- `pr_stats` L144–149
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectVault.php';`
- 加载：`require_once __DIR__ . '/ProjectRenewalMath.php';`

## includes/ProjectReviewPolicy.php (161 行, 10.4 KB)

- `prp_cash_independent` L3–13
- `prp_validate` L15–19
- `prp_policies` L21–30
- `prp_allow_no_receipt` L32–38
- `prp_save` L40–56
- `prp_col` L58–63
- `prp_project_quantity_rows` L66–96
- `prp_monthly_order_eligible` L99–128
- `prp_order_context` L130–160
- 加载：`require_once __DIR__ . '/ProjectMonthly.php';`

## includes/ProjectRuleAlgo.php (362 行, 23.9 KB)

- `pra_ensure` L13–46
- `pra_money` L48–48
- `pra_pct` L49–49
- `pra_formula` L55–77
- `pra_pairs` L80–100
- `pra_my_algorithms` L105–169
- `pra_monthly_text` L172–210
- `pra_my_fixed_income` L213–229
- `pra_actor_name` L233–240
- `pra_number` L242–247
- `pra_submit` L249–286
- `pra_attach` L289–313
- `pra_request_visible` L315–318
- `pra_my_requests` L320–326
- `pra_requests` L328–333
- `pra_pending_count` L335–339
- `pra_handle` L341–361
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`

## includes/ProjectSettlement.php (1257 行, 80.4 KB)

- `ps_actor` L7–15
- `ps_governance_has_business` L18–28
- `ps_require_actor` L30–69
- `ps_require_finance` L71–76
- `ps_csrf_token` L78–82
- `ps_check_csrf` L84–87
- `ps_order` L89–111
- `ps_audit` L113–117
- `ps_costs` L119–124
- `ps_cash_movements` L126–131
- `ps_recalculate_cash` L133–145
- `ps_participants` L147–152
- `ps_role_keys` L155–163
- `ps_rule_for` L169–194
- `ps_rules_for_person` L200–219
- `ps_role_rule_order_kind` L222–230
- `ps_rule` L232–235
- `money_plain` L237–240
- `ps_calc_person` L250–289
- `ps_trademark_piece_calc` L295–304
- `ps_summary` L306–508
- `ps_group_share_cents` L514–534
- `ps_allocate_pool_cents` L536–547
- `ps_group_subsidy_cents` L550–567
- `ps_settlement_preview` L569–576
- `ps_technical_reconciliation_summary` L578–588
- `ps_private_dir` L597–603
- `ps_private_store` L606–613
- `ps_private_read` L616–623
- `ps_private_delete` L626–632
- `ps_private_temp_copy` L635–642
- `ps_detect_mime` L648–656
- `ps_detect_file_mime` L658–665
- `ps_upload_proof` L667–676
- `ps_approve_order` L678–752
- `ps_label` L755–766
- `ps_order_todos` L772–789
- `ps_mask_contact` L795–803
- `ps_mask_id` L806–814
- `ps_contact_for` L817–821
- `ps_next_open_month` L824–834
- `ps_post_adjustment` L841–916
- `ps_reclassify_order_kind` L919–979
- `ps_order_requests` L983–1004
- `ps_order_pending_request` L1006–1021
- `ps_create_order_request` L1023–1054
- `ps_review_order_request` L1056–1139
- `ps_auto_finish_trade_success_orders` L1146–1153
- `ps_refund_later` L1156–1166
- `ps_order_asof` L1169–1175
- `ps_order_assign_backend` L1183–1256
- 加载：`require_once __DIR__ . '/auth.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectSystem.php';`
- 加载：`require_once __DIR__ . '/ProjectDepartmentImport.php';`
- 加载：`require_once __DIR__ . '/ProjectSiteProjects.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderItems.php';`
- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`

## includes/ProjectSheetEdit.php (328 行, 20.9 KB)

- `pse_ensure` L16–28
- `pse_file` L31–34
- `pse_cell` L36–41
- `pse_excel_date` L44–48
- `pse_parse` L51–105
- `pse_editable_kind` L107–107
- `pse_overlay` L109–117
- `pse_load` L120–145
- `pse_save` L148–173
- `pse_domain_clean` L175–182
- `pse_set_phone` L185–203
- `pse_parse_owner` L206–215
- `pse_set_domain_owner` L218–231
- `pse_set_server_expiry` L234–255
- `pse_set_domain` L258–272
- `pse_submit` L278–327
- 加载：`require_once __DIR__ . '/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderFix.php';`
- 加载：`require_once __DIR__ . '/ProjectRenewalImport.php';`
- 加载：`require_once __DIR__ . '/ProjectImportResult.php';`

## includes/ProjectShopState.php (23 行, 1.2 KB)

- `ps_shop_statement_merge` L3–22

## includes/ProjectSiteProjects.php (125 行, 7.7 KB)

- `psp_is_website` L4–7
- `psp_key` L9–14
- `psp_child_no` L16–21
- `psp_lookup` L23–28
- `psp_order` L30–35
- `psp_members` L37–45
- `psp_register` L47–58
- `psp_allocation_hash` L60–65
- `psp_validate_allocation` L67–83
- `psp_verify` L85–106
- `psp_verification` L108–113
- `psp_approve_guard` L115–124
- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`

## includes/ProjectSystem.php (275 行, 15 KB)

- `ps_setting_get` L7–22
- `ps_setting_set` L24–29
- `ps_contact_roles` L33–36
- `ps_contact_policy` L39–45
- `ps_contact_visible` L47–52
- `ps_ai_config` L56–65
- `ps_ai_ready` L67–71
- `ps_ai_mask_key` L73–77
- `ps_ai_endpoint` L80–85
- `ps_ai_chat` L91–126
- `ps_ai_json` L129–138
- `ps_ai_parse_order` L143–172
- `ps_php_cost_default` L182–185
- `ps_php_cost_config` L187–195
- `ps_is_php_cost` L198–201
- `ps_php_cost_for` L204–217
- `ps_admin_reviewers` L222–239
- `ps_business_reviewers` L242–254
- `ps_business_reviewer` L257–262
- `ps_actor_can_review_business` L265–273

## includes/ProjectTrademarkCost.php (113 行, 6.6 KB)

- `ptc_templates` L11–14
- `ptc_kind` L16–19
- `ptc_keyword` L22–25
- `ptc_detect_service` L28–39
- `ptc_order_has_costs` L41–46
- `ptc_count_label` L48–51
- `ptc_apply` L54–62
- `ptc_user_add` L65–87
- `ptc_backfill` L90–112
- 加载：`require_once __DIR__ . '/ProjectIntake.php';`

## includes/ProjectVault.php (295 行, 18.5 KB)

- `pv_key` L16–25
- `pv_encrypt` L27–36
- `pv_decrypt` L38–47
- `pv_access_list` L50–58
- `pv_admin_username` L60–65
- `pv_can_access` L67–73
- `pv_can_manage_access` L76–79
- `pv_require_access` L81–86
- `pv_actor_name` L88–97
- `pv_guess_category` L100–106
- `pv_mask_secrets` L109–118
- `pv_unmask` L120–123
- `pv_parse_local` L126–157
- `pv_parse_paste` L163–205
- `pv_items` L207–216
- `pv_item_save` L218–243
- `pv_order_enabled` L246–249
- `pv_order_credentials` L251–256
- `pv_search_order_hits` L262–280
- `pv_order_credential_save` L282–294
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`include $file : null;`

## includes/ProjectWelfare.php (253 行, 17.3 KB)

- `pw_policy` L4–7
- `pw_quarter` L9–19
- `pw_bounds` L21–31
- `pw_previous_quarter` L33–37
- `pw_balance` L39–42
- `pw_balance_as_of` L44–49
- `pw_ledger` L51–56
- `pw_chair_earned` L58–62
- `pw_committee_earned` L68–72
- `pw_close_quarter` L75–149
- `pw_award_quarter` L152–195
- `pw_award_year` L197–252
- 加载：`require_once __DIR__ . '/ProjectGovernance.php';`

## includes/SalaryCalculator.php (2234 行, 111.6 KB)

- `SalaryCalculator` L13–2233
- `SalaryCalculator::getLastError` L18–21
- `SalaryCalculator::dir` L23–32
- `SalaryCalculator::getConfigFile` L36–39
- `SalaryCalculator::hasCustomConfig` L41–44
- `SalaryCalculator::getEmployeeAlgorithmFile` L46–49
- `SalaryCalculator::getLegacyFile` L51–54
- `SalaryCalculator::hasCustomAlgorithm` L56–59
- `SalaryCalculator::hasAnyCustomConfig` L64–67
- `SalaryCalculator::calculate` L82–228
- `SalaryCalculator::runModule` L232–255
- `SalaryCalculator::calcBaseSalary` L258–268
- `SalaryCalculator::calcRefundDeduction` L271–406
- `SalaryCalculator::calcBaseSalaryTiered` L409–434
- `SalaryCalculator::calcStandard` L437–681
- `SalaryCalculator::calcProfitCommission` L685–726
- `SalaryCalculator::calcTrademarkCommission` L729–779
- `SalaryCalculator::calcTrademarkCashback` L782–823
- `SalaryCalculator::calcTiered` L826–1018
- `SalaryCalculator::calcPerOrder` L1021–1136
- `SalaryCalculator::calcReferralOrder` L1139–1339
- `SalaryCalculator::filterOrderTotal` L1342–1382
- `SalaryCalculator::filterOrderCount` L1385–1425
- `SalaryCalculator::calcAttendanceFull` L1428–1510
- `SalaryCalculator::calcAttendanceDaily` L1513–1524
- `SalaryCalculator::calcAttendanceDeduct` L1527–1538
- `SalaryCalculator::calcCustomerReward` L1541–1618
- `SalaryCalculator::extractWangwang` L1620–1643
- `SalaryCalculator::calcMiniProgramCommission` L1646–1772
- `SalaryCalculator::calcFixedSubsidy` L1775–1783
- `SalaryCalculator::calcCsPerformance` L1789–1806
- `SalaryCalculator::autoDeptPerf` L1815–1838
- `SalaryCalculator::saveModulesConfig` L1845–1967
- `SalaryCalculator::readModulesConfig` L1972–1983
- `SalaryCalculator::deleteCustomConfig` L1988–1996
- `SalaryCalculator::getAvailableTypes` L2000–2199
- `SalaryCalculator::createDefaultAlgorithm` L2203–2227
- `SalaryCalculator::readAlgorithm` L2229–2229
- `SalaryCalculator::createEmployeeAlgorithm` L2230–2230
- `SalaryCalculator::saveEmployeeAlgorithm` L2231–2231
- `SalaryCalculator::deleteEmployeeAlgorithm` L2232–2232
- 加载：`include $legacyFile;`

## includes/auth.php (55 行, 1.5 KB)

- `is_logged_in` L27–30
- `require_login` L35–42
- `current_admin` L47–54
- 加载：`require_once __DIR__ . '/../config/database.php';`
- 加载：`require_once __DIR__ . '/functions.php';`

## includes/auto_review_card.php (25 行, 4.1 KB)

- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`

## includes/commission_explain.php (266 行, 18.4 KB)

- `ps_yuan` L10–13
- `ps_pct` L15–18
- `ps_explain_calc_steps` L21–80
- `ps_explain_snapshot_steps` L82–95
- `ps_explain_table` L97–106
- `ps_render_calc_content` L113–149
- `ps_render_calc_modal` L151–155
- `ps_corr_ensure` L159–185
- `ps_corr_actor_name` L187–196
- `ps_corr_submit` L198–219
- `ps_corr_for_order` L221–227
- `ps_corr_pending_count` L229–237
- `ps_corr_handle` L239–265

## includes/correction_tabs.php (35 行, 2.8 KB)

- `pc_counts` L9–9
- `pc_pending_total` L10–10
- `pc_first_url` L12–17
- `pc_tabs` L18–33
- 加载：`require_once __DIR__ . '/commission_explain.php';`
- 加载：`require_once __DIR__ . '/ProjectRuleAlgo.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderFix.php';`

## includes/domain_missing_widget.php (64 行, 3.9 KB)

- `dmw_missing` L11–31
- 加载：`require_once __DIR__ . '/ProjectRenewals.php';`

## includes/dup_feedback.php (133 行, 6.7 KB)

- `pd_is_dedicated_finance` L9–12
- `pd_admin_username` L14–24
- `pd_ensure` L26–56
- `pd_message` L58–66
- `pd_department_heads` L69–75
- `pd_ask` L78–89
- `pd_can_view` L91–96
- `pd_answer` L98–113
- `pd_close` L115–121
- `pd_answered_count` L124–132
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`

## includes/etmll_push.php (109 行, 7.1 KB)

- `etmll_sync_cutoff` L14–17
- `etmll_push_shop_merchants` L20–27
- `etmll_push_since` L29–32
- `etmll_push_run` L39–97
- `etmll_push_status` L100–108
- 加载：`require_once __DIR__ . '/etmll_sync.php';`

## includes/etmll_sync.php (478 行, 25.9 KB)

- `etmll_connect` L21–44
- `etmll_map_order` L49–128
- `etmll_sync_state_table` L130–140
- `etmll_source_select_sql` L147–156
- `etmll_sync_runs_table` L158–167
- `etmll_sync_run` L169–198
- `etmll_sync_signature` L201–213
- `etmll_sync_identity` L215–218
- `etmll_sync_mark_batch` L221–230
- `etmll_sync_inventory` L233–258
- `etmll_sync_orders` L261–409
- `etmll_sync_status` L414–477
- 加载：`require_once __DIR__ . '/../config/etmll.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`

## includes/finance_taobao_card.php (36 行, 2.5 KB)


## includes/footer.php (24 行, 1.4 KB)


## includes/functions.php (2859 行, 131.6 KB)

- `e` L9–16
- `extract_order_no` L24–54
- `extract_shop_from_raw` L61–85
- `match_shop_name` L95–153
- `ensureOrderNoColumn` L159–184
- `money` L189–192
- `extract_amount` L208–229
- `parse_ssl_amount` L243–262
- `domain_years` L276–296
- `eval_amount_expr` L306–358
- `get_order_fee_info` L372–483
- `ensureAttendanceTable` L488–543
- `get_attendance_hidden_years` L548–555
- `hide_attendance_year` L560–563
- `get_attendance_custom_years` L568–575
- `add_attendance_custom_year` L580–585
- `get_attendance` L590–595
- `backfill_pending_attendance` L604–634
- `get_attendances_by_month` L639–648
- `get_attendance_years` L653–660
- `get_attendance_months` L665–674
- `get_abnormal_orders` L686–1016
- `json_response` L1021–1027
- `get_departments` L1033–1045
- `get_department_list` L1050–1058
- `get_department` L1063–1068
- `get_shops` L1073–1081
- `get_shop_list` L1086–1094
- `get_shop` L1099–1104
- `get_employees` L1109–1118
- `get_employee` L1123–1128
- `export_csv` L1136–1151
- `export_excel` L1156–1189
- `cs_perf_cache` L1198–1204
- `cs_perf_cache_get` L1205–1210
- `cs_perf_cache_set` L1211–1215
- `cs_perf_cache_reset` L1216–1219
- `ensureCsPerfSchema` L1225–1445
- `get_cs_perf_participants` L1452–1478
- `get_cs_perf_excluded` L1484–1494
- `is_cs_perf_excluded` L1499–1514
- `exclude_cs_perf_member` L1519–1530
- `include_cs_perf_member` L1535–1545
- `get_cs_performance` L1552–1586
- `get_cs_performance_stores` L1592–1606
- `cs_perf_conv_derivation` L1617–1624
- `get_employee_order_aggregate` L1632–1665
- `get_employee_deal_count` L1671–1674
- `get_employee_order_total` L1680–1683
- `get_cs_perf_schemes` L1691–1702
- `get_cs_perf_scheme` L1707–1722
- `cs_perf_tiers_parse` L1728–1749
- `cs_perf_tiers_json` L1754–1769
- `cs_perf_tier_lookup` L1779–1790
- `cs_perf_fmt_range` L1795–1803
- `save_cs_perf_scheme` L1813–1847
- `delete_cs_perf_scheme` L1852–1872
- `get_cs_perf_dept_configs` L1879–1891
- `get_cs_perf_dept_config` L1896–1912
- `save_cs_perf_dept_config` L1917–1933
- `delete_cs_perf_dept_config` L1938–1947
- `cs_perf_scheme_params` L1960–1969
- `cs_perf_metric_details` L1979–2007
- `cs_perf_composite_from` L2015–2024
- `cs_perf_calc` L2037–2044
- `cs_perf_calc_detail` L2063–2222
- `cs_perf_rank_detail` L2228–2284
- `cs_perf_rank_result` L2296–2339
- `cs_perf_rank_list` L2345–2408
- `get_cs_perf_target_suggestions` L2417–2466
- `detect_cs_perf_columns` L2473–2509
- `parse_percent` L2516–2528
- `parse_duration_to_seconds` L2538–2560
- `csv_parse_line` L2568–2590
- `normalize_cs_wangwang` L2595–2604
- `import_cs_perf_file` L2621–2849
- 加载：`include $deptConfigFile;`
- 加载：`include $deptFeeFile;`
- 加载：`require_once dirname(__DIR__) . '/classes/SimpleXLSX.php';`

## includes/header.php (226 行, 24 KB)

- 加载：`require_once __DIR__ . '/ProjectVault.php';`
- 加载：`require_once __DIR__ . '/commission_explain.php';`
- 加载：`require_once __DIR__ . '/dup_feedback.php';`
- 加载：`require_once __DIR__ . '/ProjectCostRequests.php';`
- 加载：`require_once __DIR__ . '/ProjectRuleAlgo.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderFix.php';`
- 加载：`require_once __DIR__ . '/commission_explain.php';`
- 加载：`require_once __DIR__ . '/correction_tabs.php';`
- 加载：`require_once __DIR__ . '/dup_feedback.php';`
- 加载：`include __DIR__ . '/renewal_nav.php';`
- 加载：`include __DIR__ . '/import_followup_modal.php';`

## includes/import_followup_modal.php (59 行, 7.4 KB)


## includes/joint_settlement_rules.php (8 行, 1.7 KB)


## includes/kb_chat_examples_card.php (55 行, 4.4 KB)

- 加载：`require_once __DIR__ . '/ProjectKnowledgeSkills.php';`

## includes/order_credentials_card.php (88 行, 11 KB)

- 加载：`require_once __DIR__ . '/ProjectVault.php';`

## includes/order_renewal_card.php (11 行, 2 KB)

- 加载：`require_once __DIR__.'/ProjectRenewals.php';`

## includes/project_order_items_view.php (20 行, 3.8 KB)


## includes/refund_trash_action.php (9 行, 0.7 KB)


## includes/renewal_dashboard_card.php (15 行, 1.2 KB)

- 加载：`require_once __DIR__.'/ProjectRenewals.php';`

## includes/renewal_due_widget.php (126 行, 9.2 KB)

- `rdw_contacts` L11–24
- `rdw_due` L29–59
- 加载：`require_once __DIR__ . '/ProjectRenewals.php';`

## includes/renewal_info_popup.php (100 行, 7.8 KB)

- `rip_missing` L14–45
- 加载：`require_once __DIR__ . '/ProjectRenewals.php';`

## includes/renewal_nav.php (7 行, 0.3 KB)

- 加载：`require_once __DIR__.'/ProjectRenewals.php';`

## includes/review_policy_editor.php (25 行, 4 KB)

- 加载：`require_once __DIR__ . '/ProjectReviewPolicy.php';`

## includes/rule_algo_card.php (135 行, 16.6 KB)

- `ra_f` L8–8
- `ra_asset` L9–9
- 加载：`require_once __DIR__ . '/ProjectRuleAlgo.php';`

## index.php (164 行, 11.6 KB)

- 加载：`require_once __DIR__ . '/includes/auth.php';`
- 加载：`include __DIR__ . '/includes/header.php';`
- 加载：`require_once __DIR__ . '/includes/ProjectRenewals.php';`
- 加载：`include __DIR__ . '/includes/renewal_due_widget.php';`
- 加载：`include __DIR__ . '/includes/domain_missing_widget.php';`
- 加载：`include __DIR__ . '/includes/renewal_info_popup.php';`
- 加载：`include __DIR__ . '/includes/rule_algo_card.php';`
- 加载：`include __DIR__ . '/includes/footer.php';`

## insurance/index.php (157 行, 7.3 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include $configPath;`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## jobs/etmll_sync_auto.php (19 行, 1.4 KB)

- 加载：`require_once __DIR__ . '/../includes/etmll_push.php';`

## jobs/governance_penalty_sync.php (45 行, 2.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectWelfare.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## jobs/order_auto_finish_sync.php (16 行, 0.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## jobs/project_auto_review.php (30 行, 1.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectAutoReview.php';`

## jobs/refund_auto_apply.php (13 行, 1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`

## jobs/renewal_sms.php (9 行, 0.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRenewalSms.php';`

## login.php (140 行, 8.4 KB)

- 加载：`require_once __DIR__ . '/includes/auth.php';`

## logout.php (7 行, 0.1 KB)

- 加载：`require_once __DIR__ . '/includes/auth.php';`

## migrations/apply_auto_review.php (11 行, 0.8 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_elections.php (11 行, 0.5 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_governance.php (33 行, 1.7 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_import_followups.php (7 行, 0.3 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_knowledge.php (11 行, 0.5 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_knowledge_categories.php (9 行, 0.4 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_mini_custom_cs.php (11 行, 0.8 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectSettlement.php';`

## migrations/apply_project.php (54 行, 2.8 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_refund_trash.php (9 行, 0.8 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_renewals.php (7 行, 0.3 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_review_rule_policies.php (6 行, 0.2 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectSettlement.php';`

## migrations/apply_vault.php (16 行, 0.9 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_welfare.php (11 行, 0.5 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/import_contribution_history.php (55 行, 4.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`

## migrations/provision_governance_accounts.php (37 行, 2.1 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/provision_graphic_designer.php (39 行, 2.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## migrations/provision_vault_accounts.php (51 行, 3.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## orders/edit.php (219 行, 9.8 KB)

- `_caiwu_valid_back` L89–98
- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/functions.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## orders/index.php (2520 行, 155.6 KB)

- `applyOrderVerification` L73–219
- `parseOrderDate` L954–986
- `ensureProjectColumn` L988–1071
- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`
- 加载：`include $deptConfigFile;`
- 加载：`include $deptFeeFile) : [];`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## orders/pending.php (329 行, 17.6 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/functions.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## orders/recycle.php (359 行, 18.8 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/functions.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## performance/index.php (534 行, 32.4 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## performance/month.php (527 行, 39.6 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## performance/schemes.php (454 行, 28.7 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/ai.php (28 行, 1.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`

## project/api_settings.php (56 行, 4.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectApiSettings.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/calc_modal.php (28 行, 1.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/commission_explain.php';`

## project/contributions.php (267 行, 25.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/corrections.php (51 行, 5.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/commission_explain.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`require_once __DIR__ . '/../includes/correction_tabs.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/credentials.php (60 行, 4.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectVault.php';`

## project/dashboard.php (187 行, 19.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectExpectedSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectWelfare.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/renewal_dashboard_card.php';`
- 加载：`include __DIR__ . '/../includes/kb_chat_examples_card.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/dup_feedback.php (47 行, 3.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/dup_feedback.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/file_sheet_api.php (27 行, 1.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSheetEdit.php';`

## project/files.php (217 行, 26.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportResult.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/governance.php (199 行, 24.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/governance_election.php (70 行, 8.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/governance_evidence.php (25 行, 1.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`

## project/governance_ideas.php (295 行, 43 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/governance_rules.php (80 行, 12.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectGovernanceRuleAccess.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/holidays.php (53 行, 4.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/import.php (1239 行, 150.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSplit.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSiteProjects.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectAiFallback.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportResult.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportClassification.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRenewalImport.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderFix.php';`
- 加载：`require_once __DIR__ . '/../includes/dup_feedback.php';`
- 加载：`require_once __DIR__ . '/../includes/dup_feedback.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectAutoReview.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRenewalImport.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/import_undo_api.php (26 行, 1.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectImportUndo.php';`

## project/index.php (861 行, 99.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSplit.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportResult.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectVault.php';`
- 加载：`require_once __DIR__ . '/../includes/commission_explain.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectAutoReview.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectJointIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSheetEdit.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/renewal_due_widget.php';`
- 加载：`include __DIR__ . '/../includes/domain_missing_widget.php';`
- 加载：`include __DIR__ . '/../includes/renewal_info_popup.php';`
- 加载：`include __DIR__ . '/../includes/finance_taobao_card.php';`
- 加载：`include __DIR__ . '/../includes/rule_algo_card.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge.php (38 行, 5.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_article.php (69 行, 10.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_categories.php (27 行, 2.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_costs.php (160 行, 15.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectCostRequests.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_integrations.php (76 行, 14.2 KB)

- `pk_integration_hidden` L59–59
- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeConnectors.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_keywords.php (13 行, 0.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`

## project/knowledge_links.php (45 行, 7.5 KB)

- `pk_link_form` L20–25
- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_rules.php (14 行, 2.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/joint_settlement_rules.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_skill.php (172 行, 16.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeSkills.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_skill_import.php (116 行, 10.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeChatImport.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_skills.php (78 行, 8.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeSkills.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_skills_export.php (58 行, 6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeSkills.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/lookup.php (29 行, 1.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`

## project/messages.php (30 行, 1.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/order.php (880 行, 97.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/../includes/commission_explain.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderNo.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectAutoReview.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/auto_review_card.php';`
- 加载：`include __DIR__ . '/../includes/order_credentials_card.php';`
- 加载：`include __DIR__ . '/../includes/order_renewal_card.php';`
- 加载：`require __DIR__ . '/../includes/project_order_items_view.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/order_fix_api.php (26 行, 1.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectOrderFix.php';`

## project/order_fixes.php (50 行, 5.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectOrderFix.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`require_once __DIR__ . '/../includes/correction_tabs.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/payroll.php (310 行, 39.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/preview_as.php (59 行, 5.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/renewal_due_widget.php';`
- 加载：`include __DIR__ . '/../includes/rule_algo_card.php';`
- 加载：`include __DIR__ . '/../includes/kb_chat_examples_card.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/profile.php (73 行, 7.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/proof.php (43 行, 1.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## project/refund_trash.php (39 行, 4.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/refunds.php (179 行, 30.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRefundMatch.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/refund_trash_action.php';`
- 加载：`include __DIR__ . '/../includes/refund_trash_action.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/renewal_gaps.php (101 行, 9.9 KB)

- `rg_rows` L13–35
- 加载：`require_once __DIR__ . '/../includes/ProjectSheetEdit.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRenewals.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/renewal_sms.php (33 行, 6.3 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectRenewalSms.php';`
- 加载：`include __DIR__.'/../includes/header.php';`
- 加载：`include __DIR__.'/../includes/footer.php';`

## project/renewals.php (93 行, 15.9 KB)

- `pr_url` L44–44
- 加载：`require_once __DIR__ . '/../includes/ProjectRenewalSms.php';`
- 加载：`include __DIR__.'/../includes/header.php';`
- 加载：`include __DIR__.'/../includes/footer.php';`

## project/review.php (43 行, 7.3 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectAutoReview.php';`
- 加载：`include __DIR__.'/../includes/header.php';`
- 加载：`include __DIR__.'/../includes/footer.php';`

## project/rule_request_api.php (47 行, 2.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';`

## project/rule_requests.php (54 行, 6.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`require_once __DIR__ . '/../includes/correction_tabs.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/rules.php (420 行, 67.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`require __DIR__ . '/governance_rules.php';`
- 加载：`require __DIR__ . '/welfare_rules.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectReviewPolicy.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/joint_settlement_rules.php';`
- 加载：`include __DIR__ . '/../includes/review_policy_editor.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/settings.php (299 行, 45.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/site_group.php (57 行, 5.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSplit.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSiteProjects.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/system.php (193 行, 21.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectAiFallback.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/vault.php (226 行, 24.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectVault.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/welfare.php (222 行, 29.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectWelfare.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/welfare_rules.php (15 行, 4.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectWelfare.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## salaries/query.php (228 行, 10 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/functions.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## salaries/settle.php (1109 行, 66.8 KB)

- `calcFullAttendanceBonus` L24–54
- `calcProratedBaseSalary` L61–93
- `applyProratedBaseSalary` L97–143
- `loadEmployeeOrdersWithDept` L157–257
- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`
- 加载：`include __DIR__ . '/../config/insurance.php';`
- 加载：`include __DIR__ . '/../config/dept_config.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## shops/etmll_sync.php (238 行, 16 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/etmll_sync.php';`
- 加载：`require_once __DIR__ . '/../includes/etmll_push.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## shops/index.php (257 行, 11.5 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## shops/upload.php (956 行, 66.2 KB)

- `extract_order_status` L25–39
- `ensureShopColumn` L44–55
- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectShopState.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## storage/private/imports/202610_17ae2071d11e95622ea6dac5.csv.php (5 行, 0.6 KB)


## storage/private/imports/202610_592b4a83926122369b623243.csv.php (5 行, 0.6 KB)


## storage/private/imports/bid_test_57911781df.xlsx.php (48 行, 13.8 KB)


## storage/private/imports/bid_test_c1af530e71.xlsx.php (48 行, 13.8 KB)


## storage/private/imports/followup_test_34f20ba2c4.csv.php (10 行, 0.7 KB)


## storage/private/imports/followup_test_3e03b70643.csv.php (10 行, 0.7 KB)


## storage/private/imports/followup_test_daa3a430ae.csv.php (10 行, 0.7 KB)


## storage/private/imports/followup_test_f46b350a64.csv.php (10 行, 0.7 KB)


## storage/private/imports/graphic_test_0c232dd9aa.csv.php (9 行, 0.5 KB)


## storage/private/imports/graphic_test_fd21993f8f.csv.php (9 行, 0.5 KB)


## storage/private/imports/index.php (2 行, 0 KB)


## storage/private/imports/lenient_test_1539347fe3.csv.php (7 行, 0.8 KB)


## storage/private/imports/lenient_test_81a23b4c90.csv.php (7 行, 0.8 KB)


## storage/private/imports/replay_test_b481b51c8f.csv.php (9 行, 0.9 KB)


## storage/private/imports/replay_test_e86471c441.csv.php (9 行, 0.9 KB)


## storage/private/imports/split_test_3a331cc67e.csv.php (4 行, 0.1 KB)


## storage/private/imports/split_test_9c8b5d6815.csv.php (4 行, 0.1 KB)


## storage/private/imports/tm_cost_4063d67ec6.csv.php (8 行, 0.5 KB)


## storage/private/imports/trademark_flow_a6eac782b3.xlsx.php (64 行, 19.5 KB)


## storage/private/imports/web_split_9c61f5d882.csv.php (4 行, 0.2 KB)


## storage/private/imports/web_split_bab053110d.csv.php (4 行, 0.2 KB)


## tests/attendance_approval_smoke.php (45 行, 3.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../project/rules.php';`
- 加载：`include __DIR__ . '/../project/rules.php';`

## tests/bid_flow_202608.php (67 行, 5.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/custom_site_cs_smoke.php (44 行, 3.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/dept_orders_compare_202608.php (140 行, 9.5 KB)

- 加载：`require __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/designer_split_import_smoke.php (86 行, 8.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/etmll_sync_smoke.php (131 行, 12.1 KB)

- `etmll_check` L6–9
- 加载：`require_once __DIR__ . '/../includes/etmll_sync.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectShopState.php';`
- 加载：`include missing shop flows and source updates');`
- 加载：`include verified/new creation and exclude deleted/old orders');`

## tests/governance_contributions_smoke.php (111 行, 10.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../project/contributions.php';`
- 加载：`include __DIR__ . '/../project/governance.php';`
- 加载：`include __DIR__ . '/../project/settings.php';`
- 加载：`include __DIR__ . '/../project/settings.php';`

## tests/governance_reminders_smoke.php (125 行, 13.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectWelfare.php';`

## tests/governance_rule_access.php (18 行, 1.9 KB)

- `gra_check` L5–5
- 加载：`require_once __DIR__ . '/../includes/ProjectGovernanceRuleAccess.php';`

## tests/graphic_design_flow_smoke.php (75 行, 6.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/graphic_design_package_smoke.php (53 行, 4.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/import_followup_smoke.php (69 行, 6.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../includes/import_followup_modal.php';`
- 加载：`include __DIR__ . '/../includes/import_followup_modal.php';`

## tests/import_lenient_people_smoke.php (62 行, 5.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/import_replay_fixes_smoke.php (83 行, 8.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/knowledge_categories_unit.php (25 行, 1.8 KB)

- `kbc_check` L5–5
- `kbc_reject` L6–6
- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`

## tests/order_no_canonical_smoke.php (26 行, 1.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/project_algorithm_smoke.php (242 行, 21.9 KB)

- `check_algorithm` L7–10
- `algorithm_person` L12–17
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`

## tests/project_auto_review_etmll.php (21 行, 2.3 KB)

- `are_check` L4–4
- `are_map` L5–5
- 加载：`require_once __DIR__.'/../includes/etmll_sync.php';`

## tests/project_auto_review_storage.php (50 行, 7.8 KB)

- `ars_check` L6–6
- 加载：`require_once __DIR__.'/../includes/ProjectAutoReview.php';`

## tests/project_auto_review_unit.php (97 行, 14.2 KB)

- `ar_check` L5–5
- `ar_base` L6–12
- `ar_state` L13–13
- 加载：`require_once __DIR__.'/../includes/ProjectAutoReviewMath.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectBusiness.php';`

## tests/project_dashboard_order_links.php (32 行, 2.4 KB)

- `dashboard_link_check` L6–10
- 加载：`require_once __DIR__ . '/split_source.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';`

## tests/project_department_import_flow_smoke.php (52 行, 4.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/project_department_import_smoke.php (67 行, 5.1 KB)

- `department_check` L6–9
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/project_expected_settlement.php (85 行, 8.1 KB)

- `expect_value` L7–12
- `test_rule` L13–15
- 加载：`require_once __DIR__ . '/split_source.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectExpectedSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportClassification.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/project_finance_smoke.php (140 行, 11.1 KB)

- `check_project_value` L5–10
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`include __DIR__ . '/../project/order.php';`
- 加载：`include __DIR__ . '/../project/payroll.php';`
- 加载：`include __DIR__ . '/../project/payroll.php';`

## tests/project_governance_cycle_smoke.php (47 行, 3.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`

## tests/project_governance_smoke.php (18 行, 1.4 KB)

- `governance_check` L3–3
- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`

## tests/project_governance_visibility_smoke.php (24 行, 1.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../project/governance_ideas.php';`

## tests/project_import_result_smoke.php (22 行, 2 KB)

- `ir_assert` L4–4
- 加载：`require_once __DIR__ . '/split_source.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportResult.php';`

## tests/project_import_visibility.php (38 行, 3 KB)

- `iv_list` L7–13
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/index.php';`

## tests/project_intake_smoke.php (274 行, 23.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`include __DIR__ . '/../project/index.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/project_joint_commission.php (68 行, 5.9 KB)

- `jc_check` L5–5
- 加载：`require_once __DIR__.'/../includes/ProjectIntake.php';`

## tests/project_joint_intake.php (12 行, 0.5 KB)

- `ji_equal` L4–4
- 加载：`require_once __DIR__.'/../includes/ProjectJointIntake.php';`

## tests/project_knowledge_storage.php (77 行, 8.7 KB)

- `kbs_check` L15–15
- `kbs_reject` L16–16
- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeConnectors.php';`

## tests/project_knowledge_unit.php (56 行, 5.8 KB)

- `kb_check` L5–8
- `kb_reject` L9–12
- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeConnectors.php';`

## tests/project_monthly_smoke.php (122 行, 10.2 KB)

- `check_monthly` L8–11
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/project_order_items_store_test.php (54 行, 4.2 KB)

- `item_check` L6–6
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/project_order_items_test.php (40 行, 3 KB)

- `check_item` L4–4
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderItems.php';`

## tests/project_order_source_smoke.php (91 行, 7.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/order.php';`

## tests/project_partner_dashboard_smoke.php (27 行, 2 KB)

- `check` L3–3
- 加载：`require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';`

## tests/project_payroll_reconcile_202608.php (192 行, 20.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/project_php_cost_smoke.php (61 行, 4 KB)

- `check_php` L7–10
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`

## tests/project_refund_import_smoke.php (131 行, 13.2 KB)

- `refund_check` L6–9
- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`include __DIR__ . '/../project/refunds.php';`

## tests/project_refund_trash_storage.php (61 行, 5.9 KB)

- `rt_check` L7–7
- `rt_denied` L8–8
- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectAutoReview.php';`

## tests/project_renewals_storage.php (73 行, 8.4 KB)

- `check` L16–16
- `reject` L17–17
- 加载：`require_once __DIR__.'/../includes/ProjectRenewalSms.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectRenewalImport.php';`

## tests/project_renewals_unit.php (63 行, 5.5 KB)

- `check` L4–4
- `reject` L5–5
- 加载：`require_once __DIR__.'/../includes/ProjectRenewalMath.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectRenewalSms.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectRenewalImport.php';`

## tests/project_review_policy_storage.php (56 行, 6.5 KB)

- `rps_check` L6–6
- 加载：`require_once __DIR__.'/../includes/ProjectMonthly.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectReviewPolicy.php';`

## tests/project_review_policy_unit.php (27 行, 2.8 KB)

- `rp_check` L4–4
- 加载：`require_once __DIR__.'/../includes/ProjectReviewPolicy.php';`

## tests/project_site_projects_storage.php (33 行, 2.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSiteProjects.php';`

## tests/project_site_projects_unit.php (22 行, 1.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSiteProjects.php';`

## tests/project_welfare_smoke.php (78 行, 6.8 KB)

- `welfare_check` L3–3
- 加载：`require_once __DIR__ . '/../includes/ProjectWelfare.php';`
- 加载：`include __DIR__ . '/../project/welfare.php';`
- 加载：`include __DIR__ . '/../project/governance_election.php';`
- 加载：`include __DIR__ . '/../project/welfare.php';`

## tests/require_isolated_database.php (11 行, 0.5 KB)

- `require_isolated_test_database` L3–10

## tests/split_source.php (12 行, 0.5 KB)

- `split_test_source` L4–11
- 加载：`require_once __DIR__ . '/../tools/code_structure.php';`

## tests/test_dongxu_view.php (34 行, 1.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`

## tests/test_trademark_integration.php (134 行, 8.1 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`
- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`

## tests/trademark_cost_flow_smoke.php (114 行, 11.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/trademark_department_smoke.php (127 行, 6.4 KB)

- `check_eq` L11–15
- 加载：`require_once __DIR__ . '/../config/database.php';`
- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`

## tests/trademark_import_rows_smoke.php (65 行, 6.5 KB)

- `tm_check` L7–13
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/trademark_upload_flow_202608.php (81 行, 5.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/vault_smoke.php (72 行, 7.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectVault.php';`
- 加载：`include __DIR__ . '/../project/vault.php';`
- 加载：`include __DIR__ . '/../includes/order_credentials_card.php';`
- 加载：`include __DIR__ . '/../includes/order_credentials_card.php';`

## tests/website_delivery_and_upgrade_smoke.php (186 行, 12 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSystem.php';`

## tests/website_split_import_smoke.php (103 行, 9.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/wechat_writing_smoke.php (80 行, 6.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tools/audit_correct_website_business.php (63 行, 4.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportClassification.php';`

## tools/audit_dashboard_order_links.php (60 行, 4.5 KB)

- `audit_order_link_case` L12–30
- 加载：`require_once $root.'/includes/ProjectPartnerDashboard.php';`
- 加载：`require $root.'/project/index.php';`
- 加载：`require $root.'/project/dashboard.php';`

## tools/audit_dashboard_render.php (39 行, 2 KB)

- 加载：`require_once $root . '/includes/auth.php';`
- 加载：`require $root . '/project/dashboard.php';`

## tools/audit_expected_settlement.php (30 行, 2.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectExpectedSettlement.php';`

## tools/audit_governance_rule_access.php (41 行, 3.3 KB)

- 加载：`require_once $root.'/includes/ProjectKnowledge.php';`
- 加载：`require_once $root.'/includes/ProjectGovernance.php';`
- 加载：`require_once $root.'/includes/ProjectGovernanceRuleAccess.php';`
- 加载：`require $root.'/project/'.($route==='alias'?'rules.php':'governance_rules.php');`

## tools/audit_knowledge.php (46 行, 3.4 KB)

- 加载：`require_once $root.'/includes/ProjectKnowledge.php';`
- 加载：`require $root.'/project/'.$page;`

## tools/code_structure.php (112 行, 4.9 KB)

- `split_declarations` L3–51
- `split_symbols` L53–61
- `split_token_hash` L63–77
- `split_expand` L79–111

## tools/dept_orders_switch_new_algo_202608.php (116 行, 6.8 KB)

- 加载：`require __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tools/gen_code_map.php (31 行, 1.5 KB)

- 加载：`require_once __DIR__ . '/code_structure.php';`
- 加载：`include . "`\n";`

## tools/import_replay.php (77 行, 5.6 KB)

- `replay_file` L20–52
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSystem.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tools/merge_duplicate_orders_20261008.php (76 行, 6.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tools/recover_project_import.php (46 行, 3.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportResult.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSystem.php';`
- 加载：`require_once __DIR__ . '/../includes/dup_feedback.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tools/reflow_split.php (65 行, 2.9 KB)


## tools/repair_joint_customer_snapshots.php (59 行, 6 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectSettlement.php';`

## tools/repair_website_order_items.php (67 行, 4.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tools/seed_trademark_costs.php (47 行, 2.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tools/split_runtime.php (26 行, 1.2 KB)

- 加载：`require_once $root . '/includes/' . $entry;`

## tools/verify_split.php (45 行, 2.1 KB)

- 加载：`require_once __DIR__ . '/code_structure.php';`

