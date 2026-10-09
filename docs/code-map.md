# 代码地图

由 `php tools/gen_code_map.php` 生成。先搜索函数名，再按行号读取目标片段。

## abnormal/employee.php (248 行, 11.4 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## abnormal/index.php (315 行, 14.3 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## abnormal/shop.php (250 行, 11.7 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## algorithms/default.php (54 行, 1.8 KB)


## attendance/index.php (171 行, 8.1 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## attendance/month.php (2 行, 0.2 KB)

- 加载：`include __DIR__ . '/month/context.php';`
- 加载：`include __DIR__ . '/month/view.php';`

## attendance/month/actions/batch_delete.php (16 行, 0.7 KB)


## attendance/month/actions/delete.php (8 行, 0.3 KB)


## attendance/month/actions/dispatch.php (21 行, 0.8 KB)

- 加载：`include (dirname(__DIR__, 2)) . '/month/actions/manual_add.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/month/actions/delete.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/month/actions/batch_delete.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/month/actions/upload.php';`

## attendance/month/actions/manual_add.php (23 行, 1.1 KB)


## attendance/month/actions/upload.php (282 行, 18.9 KB)


## attendance/month/context.php (41 行, 1.3 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../classes/SimpleXLSX.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/month/actions/dispatch.php';`

## attendance/month/view.php (4 行, 0.2 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`

## attendance/month/view/js_1.php (53 行, 2.4 KB)


## attendance/month/view/section_1.php (206 行, 12.9 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/month/view/js_1.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php';`

## attendance/year.php (89 行, 3.8 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## captcha.php (47 行, 1.4 KB)


## check_order_no.php (32 行, 1.2 KB)

- 加载：`require_once __DIR__ . '/includes/auth.php';`

## classes/SimpleXLSX.php (333 行, 11.9 KB)

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

## departments/index.php (189 行, 8 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## employees/algorithm.php (3 行, 0.2 KB)

- 加载：`require_once __DIR__ . '/algorithm/helpers/renderModuleForm.php';`
- 加载：`include __DIR__ . '/algorithm/context.php';`
- 加载：`include __DIR__ . '/algorithm/view.php';`

## employees/algorithm/actions/dispatch.php (10 行, 0.4 KB)

- 加载：`include (dirname(__DIR__, 2)) . '/algorithm/actions/save.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/algorithm/actions/reset.php';`

## employees/algorithm/actions/reset.php (6 行, 0.2 KB)


## employees/algorithm/actions/save.php (83 行, 4.2 KB)


## employees/algorithm/context.php (44 行, 1.7 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/SalaryCalculator.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/algorithm/actions/dispatch.php';`
- 加载：`include $deptConfigFile;`
- 加载：`include $deptFeeFile;`

## employees/algorithm/helpers/renderModuleForm.php (106 行, 6.9 KB)

- `renderModuleForm` L2–105

## employees/algorithm/view.php (4 行, 0.2 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`

## employees/algorithm/view/js_2.php (66 行, 4.5 KB)

- 加载：`include __DIR__ . '/js_2/content.php';`

## employees/algorithm/view/js_2/content.php (341 行, 17.8 KB)


## employees/algorithm/view/section_1.php (238 行, 12.9 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/css/employees_algorithm_1.css';`
- 加载：`require_once (dirname((dirname(__DIR__, 1)), 1)) . '/algorithm/helpers/renderModuleForm.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/algorithm/view/js_2.php';`

## employees/index.php (242 行, 12.3 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## includes/ProjectAccountRoles.php (100 行, 4.3 KB)

- `ps_account_roles` L4–7
- `ps_account_order_role` L10–13
- `ps_management_profile` L15–25
- `ps_is_management` L27–30
- `ps_management_company` L32–35
- `ps_management_can_business` L37–43
- `ps_management_order_condition` L46–57
- `ps_management_company_employee_ids` L59–67
- `ps_account_management_save` L70–84
- `ps_management_fixed_pay_ids` L86–99


## includes/ProjectAiFallback.php (150 行, 9 KB)

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

## includes/ProjectApiSettings.php (166 行, 9.4 KB)

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

## includes/ProjectAutoReview.php (270 行, 19.5 KB)

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

## includes/ProjectAutoReviewMath.php (202 行, 17.3 KB)

- `pa_cents` L5–13
- `pa_payment_evidence` L15–69
- `pa_evaluate` L71–189
- `pa_state_meta` L191–201

## includes/ProjectBusiness.php (403 行, 33.6 KB)

- `ps_business_catalog` L8–46
- `ps_business_account_products` L49–56
- `ps_business_fallback` L58–64
- `ps_business_normalize` L66–70
- `ps_is_website_order` L72–75
- `ps_business_requires_technical` L78–81
- `ps_business_service_fee_rate` L83–86
- `ps_business_order_kinds` L88–91
- `ps_order_kind_from_role` L94–98
- `ps_actor_businesses` L100–120
- `ps_business_choice` L122–128
- `ps_require_business` L130–136
- `ps_active_employee_for_business` L138–150
- `ps_business_details` L152–165
- `ps_save_business_details` L167–171
- `ps_order_kind_valid` L173–179
- `ps_business_import_columns` L185–291
- `ps_import_role_extras` L295–317
- `ps_business_import_headers` L320–325
- `ps_business_import_headers_base` L327–350
- `ps_business_import_map` L353–371
- `ps_import_delivery_status` L376–388
- `ps_business_people_labels` L390–402
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectMiniappTemplate.php';`

## includes/ProjectCostRequests.php (228 行, 13.2 KB)

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

## includes/ProjectDepartmentImport.php (72 行, 3.3 KB)

- `ps_department_import_allowed` L5–14
- `ps_department_import_people` L16–31
- `ps_department_import_is_order` L33–38
- `ps_department_import_record` L40–48
- `ps_department_import_uploader_access` L50–55
- `ps_department_renewal_rates` L58–71
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectMonthly.php';`

## includes/ProjectExpectedSettlement.php (140 行, 8.9 KB)

- `ps_expected_snapshot_rows` L7–56
- `ps_expected_rollup` L59–83
- `ps_expected_month` L85–128
- `ps_partner_expected_income` L130–139
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundTrash.php';`

## includes/ProjectGovernance.php (10 行, 0.7 KB)

- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/governance/member_auth.php';`
- 加载：`require_once __DIR__ . '/governance/ideas_reminders.php';`
- 加载：`require_once __DIR__ . '/governance/oversight.php';`
- 加载：`require_once __DIR__ . '/governance/chair_elections.php';`
- 加载：`require_once __DIR__ . '/governance/private_files.php';`

## includes/ProjectGovernanceRuleAccess.php (22 行, 1 KB)

- `pgr_access` L3–16
- `pgr_rule_visible` L18–21

## includes/ProjectImportClassification.php (47 行, 2.4 KB)

- `ps_import_website_business` L3–13
- `ps_import_website_people_roles` L15–46

## includes/ProjectImportFiles.php (195 行, 10.5 KB)

- `ps_import_file_store` L4–17
- `ps_import_original_name` L20–25
- `ps_import_file_get` L28–43
- `ps_import_file_delete` L46–71
- `ps_import_file_sheets` L74–81
- `ps_import_file_parse` L83–98
- `ps_xlsx_sheets` L105–184
- `ps_import_file_mark` L186–194
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`

## includes/ProjectImportParse.php (211 行, 14.1 KB)

- `ps_import_date` L3–30
- `ps_import_names` L36–65
- `ps_import_split_joined_names` L68–84
- `ps_import_name_resembles_employee` L87–102
- `ps_import_names_lenient` L108–121
- `ps_import_autofill_details` L127–138
- `ps_import_fix_guide` L144–177
- `ps_import_followup_rows` L183–198
- `ps_import_public_transfer` L201–210

## includes/ProjectImportResult.php (66 行, 4 KB)

- `ps_import_upload_actor` L3–13
- `ps_import_result_get` L15–20
- `ps_import_order_visible` L22–32
- `ps_import_result_save` L34–57
- `ps_import_numeric_summary` L60–65

## includes/ProjectImportUndo.php (149 行, 9.4 KB)

- `pu_blocking_tables` L9–22
- `pu_owned_tables` L25–29
- `pu_file_for_actor` L31–39
- `pu_ids_used_by_other_files` L42–51
- `pu_plan` L54–97
- `pu_execute` L100–148
- 加载：`require_once __DIR__ . '/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/ProjectImportResult.php';`

## includes/ProjectIntake.php (12 行, 0.8 KB)

- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderItems.php';`
- 加载：`require_once __DIR__ . '/intake/templates_resources.php';`
- 加载：`require_once __DIR__ . '/intake/participants_orders.php';`
- 加载：`require_once __DIR__ . '/ProjectImportParse.php';`
- 加载：`require_once __DIR__ . '/intake/followup.php';`
- 加载：`require_once __DIR__ . '/intake/parse_rows.php';`
- 加载：`require_once __DIR__ . '/intake/parse_business.php';`
- 加载：`require_once __DIR__ . '/ProjectImportFiles.php';`

## includes/ProjectJointIntake.php (15 行, 0.7 KB)

- `ps_joint_customer_ids` L3–14

## includes/ProjectKnowledge.php (349 行, 21.5 KB)

- `pk_ready` L5–11
- `pk_is_super` L13–19
- `pk_departments` L21–44
- `pk_context` L46–55
- `pk_owner` L57–60
- `pk_readable` L62–72
- `pk_editable` L74–79
- `pk_article` L81–88
- `pk_access_sql` L91–106
- `pk_limit` L108–113
- `pk_content_post` L116–141
- `pk_url` L144–159
- `pk_keywords` L161–173
- `pk_keyword_conflicts` L175–183
- `pk_links` L185–189
- `pk_save_link` L191–227
- `pk_link_state` L229–242
- `pk_article_input` L244–263
- `pk_revision` L265–268
- `pk_save_article` L270–296
- `pk_stage_document` L299–331
- `pk_tabs` L333–341
- `pk_hero` L343–346
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectKnowledgeCategories.php';`

## includes/ProjectKnowledgeCategories.php (61 行, 4.2 KB)

- `pk_category_name` L3–11
- `pk_category_input` L13–17
- `pk_categories` L19–23
- `pk_create_category` L26–44
- `pk_category_picker` L46–60

## includes/ProjectKnowledgeChatImport.php (148 行, 9.2 KB)

- `pks_chat_header` L12–18
- `pks_chat_split_blocks` L20–27
- `pks_chat_normalize` L30–64
- `pks_chat_import_local` L66–79
- `pks_chat_import_parse` L85–128
- `pks_chat_import_save` L131–147
- 加载：`require_once __DIR__ . '/ProjectKnowledgeSkills.php';`

## includes/ProjectKnowledgeConnectors.php (178 行, 11.5 KB)

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

## includes/ProjectKnowledgeSkills.php (457 行, 24.5 KB)

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

## includes/ProjectMonthly.php (33 行, 3.4 KB)

- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/SalaryCalculator.php';`
- 加载：`require_once __DIR__ . '/monthly/rules_data.php';`
- 加载：`require_once __DIR__ . '/monthly/legacy_orders.php';`
- 加载：`require_once __DIR__ . '/ProjectMonthlyResults.php';`
- 加载：`require_once __DIR__ . '/monthly/config_presets.php';`

## includes/ProjectMonthlyResults.php (220 行, 13.2 KB)

- `ps_monthly_results` L7–219
- 加载：`include __DIR__ . '/monthly/types/tier_rate.php';`
- 加载：`include __DIR__ . '/monthly/types/threshold_bonus.php';`
- 加载：`include __DIR__ . '/monthly/types/ranking_manual.php';`
- 加载：`include __DIR__ . '/monthly/types/ranking.php';`
- 加载：`include __DIR__ . '/monthly/types/dept_share.php';`
- 加载：`include __DIR__ . '/monthly/types/profit_pool.php';`
- 加载：`include __DIR__ . '/monthly/types/perf_rank.php';`
- 加载：`include __DIR__ . '/monthly/types/order_count.php';`
- 加载：`include __DIR__ . '/monthly/types/legacy_sheet_1.php';`
- 加载：`include __DIR__ . '/monthly/types/legacy_sheet_2.php';`
- 加载：`include __DIR__ . '/monthly/types/legacy_sheet_3.php';`
- 加载：`include __DIR__ . '/monthly/types/legacy_module_1.php';`
- 加载：`include __DIR__ . '/monthly/types/legacy_module_2.php';`
- 加载：`include __DIR__ . '/monthly/types/legacy_module_3.php';`
- 加载：`include __DIR__ . '/monthly/types/legacy_module_4.php';`
- 加载：`include __DIR__ . '/monthly/types/fixed.php';`
- 加载：`include __DIR__ . '/monthly/types/base_fee_1.php';`
- 加载：`include __DIR__ . '/monthly/types/base_fee_2.php';`
- 加载：`include __DIR__ . '/monthly/types/attendance_bonus_1.php';`
- 加载：`include __DIR__ . '/monthly/types/attendance_bonus_2.php';`
- 加载：`include __DIR__ . '/monthly/types/manual.php';`
- 加载：`include __DIR__ . '/monthly/types/sales_package_1.php';`
- 加载：`include __DIR__ . '/monthly/types/sales_package_2.php';`
- 加载：`include __DIR__ . '/monthly/types/per_unit.php';`

## includes/ProjectOrderFix.php (168 行, 10.4 KB)

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

## includes/ProjectOrderItems.php (232 行, 14.6 KB)

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

## includes/ProjectOrderJoin.php (139 行, 9.1 KB)

- `poj_amount_value` L13–19
- `poj_same_sale_allowed` L22–37
- `poj_group_weights_equal` L40–47
- `poj_join_target` L50–66
- `poj_join_group` L69–86
- `poj_technician_choices` L89–100
- `poj_price_verdict` L110–125
- `poj_apply_price` L128–138
- 加载：`require_once __DIR__ . '/ProjectOrderSplit.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`

## includes/ProjectOrderList.php (139 行, 10.7 KB)


## includes/ProjectOrderNo.php (51 行, 2.9 KB)

- `pon_is_internal` L8–11
- `pon_rename` L17–50
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`

## includes/ProjectOrderSource.php (238 行, 15.9 KB)

- `ps_source_record` L4–8
- `ps_payment_reference_order_no` L11–16
- `ps_customer_intake_blocking` L19–22
- `ps_customer_intake_conflicts` L24–41
- `ps_customer_intake_conflict_detail` L44–55
- `ps_save_customer_intake` L58–108
- `ps_source_nickname` L110–120
- `ps_shop_status_rank` L127–137
- `ps_sync_project_status_latest` L140–151
- `ps_sync_project_from_shop_order` L153–186
- `ps_sync_existing_shop_order` L188–202
- `ps_shop_order_lookup` L208–237
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

## includes/ProjectPartnerDashboard.php (192 行, 14.3 KB)

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

## includes/ProjectPresets.php (184 行, 20.6 KB)

- `ps_preset_cost_templates` L10–49
- `ps_preset_rules` L56–128
- `ps_preset_template_exists` L130–137
- `ps_preset_rule_exists` L139–154
- `ps_apply_preset_templates` L157–168
- `ps_apply_preset_rules` L171–183
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`

## includes/ProjectRefundClawback.php (81 行, 6.2 KB)

- `prc_commission_rows` L7–14
- `prc_money` L16–16
- `prc_rows_text` L18–25
- `prc_clawback_for_refund` L31–60
- `prc_backfill` L63–80
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectExpectedSettlement.php';`

## includes/ProjectRefundImport.php (431 行, 32.7 KB)

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

## includes/ProjectRefundMatch.php (188 行, 14.1 KB)

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

## includes/ProjectRefundTrash.php (75 行, 4.2 KB)

- `prt_storage_available` L3–11
- `prt_active_sql` L13–17
- `prt_can_trash` L19–22
- `prt_deleted_duplicate` L24–32
- `prt_change` L34–66
- `prt_after_change` L69–74
- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`

## includes/ProjectRenewalImport.php (70 行, 6.3 KB)

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

## includes/ProjectRenewalSms.php (121 行, 11.6 KB)

- `pr_sms_config` L4–7
- `pr_sms_map` L8–14
- `pr_sms_save_config` L15–35
- `pr_sms_request` L37–51
- `pr_sms_response` L52–59
- `pr_sms_send` L60–68
- `pr_sms_run` L70–120
- 加载：`require_once __DIR__ . '/ProjectRenewals.php';`

## includes/ProjectRenewals.php (150 行, 12.3 KB)

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

## includes/ProjectReviewPolicy.php (161 行, 10.2 KB)

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

## includes/ProjectRuleAlgo.php (362 行, 23.5 KB)

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

## includes/ProjectSettlement.php (16 行, 1.1 KB)

- 加载：`require_once __DIR__ . '/auth.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectSystem.php';`
- 加载：`require_once __DIR__ . '/ProjectDepartmentImport.php';`
- 加载：`require_once __DIR__ . '/settlement/actor_auth.php';`
- 加载：`require_once __DIR__ . '/settlement/audit_db.php';`
- 加载：`require_once __DIR__ . '/settlement/rules.php';`
- 加载：`require_once __DIR__ . '/settlement/summary.php';`
- 加载：`require_once __DIR__ . '/settlement/private_files.php';`
- 加载：`require_once __DIR__ . '/settlement/approval.php';`
- 加载：`require_once __DIR__ . '/settlement/labels_todos.php';`
- 加载：`require_once __DIR__ . '/settlement/adjustment.php';`
- 加载：`require_once __DIR__ . '/settlement/requests.php';`
- 加载：`require_once __DIR__ . '/settlement/backend.php';`

## includes/ProjectSheetEdit.php (431 行, 27.2 KB)

- `pse_ensure` L16–35
- `pse_file` L38–41
- `pse_cell` L43–48
- `pse_excel_date` L51–55
- `pse_parse` L58–132
- `pse_editable_kind` L134–134
- `pse_can_edit` L137–140
- `pse_import_sheets` L143–163
- `pse_revision` L166–172
- `pse_overlay` L174–182
- `pse_load` L185–210
- `pse_save` L213–257
- `pse_domain_clean` L259–266
- `pse_set_phone` L269–287
- `pse_set_wechat` L290–305
- `pse_parse_owner` L308–317
- `pse_set_domain_owner` L320–333
- `pse_set_server_expiry` L336–357
- `pse_set_domain` L360–374
- `pse_submit` L380–430
- 加载：`require_once __DIR__ . '/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderFix.php';`
- 加载：`require_once __DIR__ . '/ProjectRenewalImport.php';`
- 加载：`require_once __DIR__ . '/ProjectImportResult.php';`

## includes/ProjectShopState.php (23 行, 1.1 KB)

- `ps_shop_statement_merge` L3–22

## includes/ProjectSiteProjects.php (133 行, 8 KB)

- `psp_is_website` L4–7
- `psp_is_addon_program` L10–15
- `psp_key` L17–22
- `psp_child_no` L24–29
- `psp_lookup` L31–36
- `psp_order` L38–43
- `psp_members` L45–53
- `psp_register` L55–66
- `psp_allocation_hash` L68–73
- `psp_validate_allocation` L75–91
- `psp_verify` L93–114
- `psp_verification` L116–121
- `psp_approve_guard` L123–132
- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`

## includes/ProjectSystem.php (275 行, 14.8 KB)

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

## includes/ProjectTrademarkCost.php (122 行, 7.2 KB)

- `ptc_templates` L12–15
- `ptc_kind` L17–20
- `ptc_keyword` L23–26
- `ptc_detect_service` L29–35
- `ptc_order_has_costs` L37–42
- `ptc_count_label` L44–47
- `ptc_apply` L50–59
- `ptc_user_add` L62–89
- `ptc_backfill` L92–114
- `ptc_sync_order_cost` L117–121
- 加载：`require_once __DIR__ . '/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/ProjectTrademarkPricing.php';`
- 加载：`require_once __DIR__ . '/ProjectTrademarkReconcile.php';`

## includes/ProjectTrademarkPricing.php (195 行, 16.3 KB)

- `ptc_service_names` L3–30
- `ptc_find_service` L32–39
- `ptc_mixed_plan` L42–71
- `ptc_cost_plan` L74–125
- `ptc_order_pricing_data` L128–134
- `ptc_import_check` L136–161
- `ptc_merge_import_details` L163–170
- `ptc_approval_guard` L173–194

## includes/ProjectTrademarkReconcile.php (98 行, 7.8 KB)

- `ptc_reconcile_order_cost` L3–97

## includes/ProjectVault.php (295 行, 18.2 KB)

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

## includes/ProjectWelfare.php (253 行, 17 KB)

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

## includes/SalaryCalculator.php (387 行, 16.9 KB)

- `SalaryCalculator` L28–386
- `SalaryCalculator::getLastError` L33–36
- `SalaryCalculator::dir` L38–47
- `SalaryCalculator::getConfigFile` L51–54
- `SalaryCalculator::hasCustomConfig` L56–59
- `SalaryCalculator::getEmployeeAlgorithmFile` L61–64
- `SalaryCalculator::getLegacyFile` L66–69
- `SalaryCalculator::hasCustomAlgorithm` L71–74
- `SalaryCalculator::hasAnyCustomConfig` L79–82
- `SalaryCalculator::calculate` L97–243
- `SalaryCalculator::runModuleFor` L253–278
- `SalaryCalculator::runModule` L282–305
- `SalaryCalculator::createDefaultAlgorithm` L356–380
- `SalaryCalculator::readAlgorithm` L382–382
- `SalaryCalculator::createEmployeeAlgorithm` L383–383
- `SalaryCalculator::saveEmployeeAlgorithm` L384–384
- `SalaryCalculator::deleteEmployeeAlgorithm` L385–385
- 加载：`require_once __DIR__ . '/salary/CalcBaseTrait.php';`
- 加载：`require_once __DIR__ . '/salary/CalcStandardTrait.php';`
- 加载：`require_once __DIR__ . '/salary/CalcProfitTrait.php';`
- 加载：`require_once __DIR__ . '/salary/CalcTrademarkTrait.php';`
- 加载：`require_once __DIR__ . '/salary/CalcTieredTrait.php';`
- 加载：`require_once __DIR__ . '/salary/CalcPerOrderTrait.php';`
- 加载：`require_once __DIR__ . '/salary/CalcReferralTrait.php';`
- 加载：`require_once __DIR__ . '/salary/CalcOrderFilterTrait.php';`
- 加载：`require_once __DIR__ . '/salary/CalcAttendanceRewardTrait.php';`
- 加载：`require_once __DIR__ . '/salary/CalcOrderCustomerTrait.php';`
- 加载：`require_once __DIR__ . '/salary/CalcMiniProgramTrait.php';`
- 加载：`require_once __DIR__ . '/salary/CalcPerformanceTrait.php';`
- 加载：`require_once __DIR__ . '/salary/ModulesConfigTrait.php';`
- 加载：`require_once __DIR__ . '/salary/ModulesTypesTrait.php';`
- 加载：`include $legacyFile;`

## includes/auth.php (55 行, 1.4 KB)

- `is_logged_in` L27–30
- `require_login` L35–42
- `current_admin` L47–54
- 加载：`require_once __DIR__ . '/../config/database.php';`
- 加载：`require_once __DIR__ . '/functions.php';`

## includes/auto_review_card.php (25 行, 4.1 KB)

- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`

## includes/commission_explain.php (266 行, 18.1 KB)

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

## includes/correction_tabs.php (35 行, 2.7 KB)

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

## includes/etmll_push.php (109 行, 7 KB)

- `etmll_sync_cutoff` L14–17
- `etmll_push_shop_merchants` L20–27
- `etmll_push_since` L29–32
- `etmll_push_run` L39–97
- `etmll_push_status` L100–108
- 加载：`require_once __DIR__ . '/etmll_sync.php';`

## includes/etmll_sync.php (478 行, 25.5 KB)

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


## includes/footer.php (24 行, 1.3 KB)


## includes/functions.php (12 行, 0.9 KB)

- 加载：`require_once __DIR__ . '/lib/order_parse.php';`
- 加载：`require_once __DIR__ . '/lib/attendance.php';`
- 加载：`require_once __DIR__ . '/lib/abnormal_orders.php';`
- 加载：`require_once __DIR__ . '/lib/master_data.php';`
- 加载：`require_once __DIR__ . '/lib/cs_perf_schema.php';`
- 加载：`require_once __DIR__ . '/lib/cs_perf_members.php';`
- 加载：`require_once __DIR__ . '/lib/cs_perf_schemes.php';`
- 加载：`require_once __DIR__ . '/lib/cs_perf_calc.php';`
- 加载：`require_once __DIR__ . '/lib/cs_perf_rank.php';`
- 加载：`require_once __DIR__ . '/lib/cs_perf_import.php';`

## includes/governance/chair_elections.php (204 行, 12 KB)

- `pg_unread_messages` L4–13
- `pg_workdays_in` L16–24
- `pg_chair_pool_amount` L27–35
- `pg_chair_term` L44–74
- `pg_sync_idea_penalties` L77–103
- `pg_idea_window_status` L109–129
- `pg_election_schedule` L132–136
- `pg_sync_election_notices` L138–153
- `pg_quarter_start` L155–160
- `pg_active_rotation` L162–168
- `pg_chair_pool` L170–203

## includes/governance/ideas_reminders.php (117 行, 7.2 KB)

- `pg_idea_policy` L3–15
- `pg_holiday_dates` L18–27
- `pg_idea_deadline` L30–41
- `pg_message` L44–49
- `pg_workdays_between` L52–60
- `pg_sync_reminders` L71–116

## includes/governance/member_auth.php (87 行, 3.4 KB)

- `pg_member` L4–11
- `pg_require_member` L13–19
- `pg_require_contribution_editor` L25–36
- `pg_actor_columns` L39–43
- `pg_uploaded_files` L46–56
- `pg_kind_label` L58–61
- `pg_review_label` L63–66
- `pg_can_review` L68–74
- `pg_validate_date` L76–86

## includes/governance/oversight.php (114 行, 6.9 KB)

- `pg_oversight_policy` L4–10
- `pg_oversight_tasks` L13–25
- `pg_oversight_done_by` L31–40
- `pg_committee_unit_plan` L45–52
- `pg_oversight_done` L55–58
- `pg_committee_team_units` L65–80
- `pg_committee_members` L82–86
- `pg_sync_oversight_penalties` L89–113

## includes/governance/private_files.php (46 行, 2.4 KB)

- `pg_private_dir` L4–7
- `pg_store_evidence` L10–19
- `pg_save_evidence_file` L22–45

## includes/header.php (228 行, 24 KB)

- 加载：`require_once __DIR__ . '/ProjectVault.php';`
- 加载：`require __DIR__ . '/workbench-nav.php';`
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

## includes/import_followup_modal.php (59 行, 7.3 KB)


## includes/intake/followup.php (41 行, 2.6 KB)

- `ps_import_followup_save` L4–29
- `ps_import_followup_get` L32–40

## includes/intake/parse_business.php (106 行, 6.8 KB)

- `ps_import_domain_mode` L3–9
- `ps_import_kind_preference` L11–21
- `ps_import_kind_preference_save` L23–29
- `ps_import_business_signature` L32–36
- `ps_import_business_detect` L39–93
- `ps_import_business_preference_save` L96–103

## includes/intake/parse_rows.php (107 行, 7.4 KB)

- `ps_business_import_example_row` L4–24
- `ps_import_row_is_example` L27–31
- `ps_import_row_is_data` L34–38
- `ps_import_headerless_map` L44–93
- `ps_import_employee_index` L96–106

## includes/intake/participants_orders.php (151 行, 8.7 KB)

- `ps_employee_default_role` L4–9
- `ps_intake_participants` L11–37
- `ps_intake_domain_suggestion` L39–53
- `ps_order_no_canonical` L56–62
- `ps_order_no_resolve` L65–81
- `ps_import_group_taken` L84–89
- `ps_trademark_technical_role_open` L95–102
- `ps_trademark_add_technical` L105–115
- `ps_trademark_fix_row` L121–150

## includes/intake/templates_resources.php (132 行, 8.2 KB)

- `ps_intake_templates` L3–13
- `ps_intake_template` L15–23
- `ps_intake_program_suggestion` L26–45
- `ps_template_cost_amount` L51–63
- `ps_template_cost_status` L66–70
- `ps_intake_add_template_cost` L72–90
- `ps_intake_save_resources` L92–98
- `ps_intake_confirm_resources` L104–131

## includes/joint_settlement_rules.php (8 行, 1.7 KB)


## includes/kb_chat_examples_card.php (55 行, 4.4 KB)

- 加载：`require_once __DIR__ . '/ProjectKnowledgeSkills.php';`

## includes/lib/abnormal_orders.php (344 行, 15.6 KB)

- `get_abnormal_orders` L13–343

## includes/lib/attendance.php (193 行, 6.9 KB)

- `ensureAttendanceTable` L6–61
- `get_attendance_hidden_years` L66–73
- `hide_attendance_year` L78–81
- `get_attendance_custom_years` L86–93
- `add_attendance_custom_year` L98–103
- `get_attendance` L108–113
- `backfill_pending_attendance` L122–152
- `get_attendances_by_month` L157–166
- `get_attendance_years` L171–178
- `get_attendance_months` L183–192

## includes/lib/cs_perf_calc.php (262 行, 13.5 KB)

- `cs_perf_metric_details` L11–43
- `cs_perf_composite_from` L51–60
- `cs_perf_calc` L73–80
- `cs_perf_calc_detail` L99–261

## includes/lib/cs_perf_import.php (357 行, 19.6 KB)

- `parse_percent` L8–20
- `parse_duration_to_seconds` L30–52
- `csv_parse_line` L60–82
- `normalize_cs_wangwang` L87–96
- `import_cs_perf_file` L113–347
- 加载：`require_once dirname((dirname(__DIR__, 1))) . '/classes/SimpleXLSX.php';`

## includes/lib/cs_perf_members.php (242 行, 10.5 KB)

- `get_cs_perf_participants` L8–34
- `get_cs_perf_excluded` L40–50
- `is_cs_perf_excluded` L55–70
- `exclude_cs_perf_member` L75–86
- `include_cs_perf_member` L91–101
- `get_cs_performance` L108–142
- `get_cs_performance_stores` L148–162
- `cs_perf_conv_derivation` L173–180
- `get_employee_order_aggregate` L188–221
- `get_employee_deal_count` L227–230
- `get_employee_order_total` L236–239

## includes/lib/cs_perf_rank.php (295 行, 15.1 KB)

- `cs_perf_rank_detail` L7–63
- `cs_perf_rank_result` L75–118
- `cs_perf_rank_list` L124–187
- `get_cs_perf_target_suggestions` L196–245
- `detect_cs_perf_columns` L252–294

## includes/lib/cs_perf_schema.php (257 行, 14.7 KB)

- `cs_perf_cache` L8–14
- `cs_perf_cache_get` L15–20
- `cs_perf_cache_set` L21–25
- `cs_perf_cache_reset` L26–29
- `ensureCsPerfSchema` L35–256

## includes/lib/cs_perf_schemes.php (288 行, 10.9 KB)

- `get_cs_perf_schemes` L7–18
- `get_cs_perf_scheme` L23–38
- `cs_perf_tiers_parse` L44–65
- `cs_perf_tiers_json` L70–85
- `cs_perf_tier_lookup` L95–106
- `cs_perf_fmt_range` L111–119
- `save_cs_perf_scheme` L129–165
- `delete_cs_perf_scheme` L170–190
- `get_cs_perf_dept_configs` L197–209
- `get_cs_perf_dept_config` L214–230
- `save_cs_perf_dept_config` L235–251
- `delete_cs_perf_dept_config` L256–265
- `cs_perf_scheme_params` L278–287

## includes/lib/master_data.php (178 行, 4.6 KB)

- `json_response` L6–12
- `get_departments` L18–30
- `get_department_list` L35–43
- `get_department` L48–53
- `get_shops` L58–66
- `get_shop_list` L71–80
- `get_shop` L85–90
- `get_employees` L95–104
- `get_employee` L109–114
- `export_csv` L122–137
- `export_excel` L142–175

## includes/lib/order_amount.php (301 行, 10.6 KB)

- `money` L6–9
- `extract_amount` L25–46
- `parse_ssl_amount` L60–79
- `domain_years` L93–113
- `eval_amount_expr` L123–175
- `get_order_fee_info` L189–300
- 加载：`include $deptConfigFile;`
- 加载：`include $deptFeeFile;`

## includes/lib/order_parse.php (4 行, 0.2 KB)

- 加载：`require_once __DIR__ . '/order_text.php';`
- 加载：`require_once __DIR__ . '/order_amount.php';`

## includes/lib/order_text.php (185 行, 7.1 KB)

- `e` L9–16
- `extract_order_no` L24–54
- `extract_shop_from_raw` L61–85
- `match_shop_name` L95–153
- `ensureOrderNoColumn` L159–184

## includes/monthly/config_presets.php (4 行, 0.2 KB)

- 加载：`require_once __DIR__ . '/inputs_freeze.php';`
- 加载：`require_once __DIR__ . '/presets.php';`

## includes/monthly/inputs_freeze.php (112 行, 8.4 KB)

- `ps_monthly_freeze` L3–11
- `ps_monthly_params_from_input` L14–111

## includes/monthly/legacy_orders.php (100 行, 5.2 KB)

- `ps_legacy_sheet_orders` L10–99
- 加载：`require_once (dirname(__DIR__, 1)) . '/ProjectReviewPolicy.php';`

## includes/monthly/presets.php (159 行, 17.2 KB)

- `ps_monthly_presets` L7–112
- `ps_monthly_apply_presets` L114–158

## includes/monthly/rules_data.php (183 行, 10.2 KB)

- `ps_monthly_types` L3–22
- `ps_monthly_metrics` L24–27
- `ps_monthly_rules_for` L29–38
- `ps_monthly_scope_businesses` L40–44
- `ps_monthly_snapshot_matches` L46–54
- `ps_monthly_snapshots` L56–73
- `ps_monthly_snapshot_values` L79–88
- `ps_monthly_attendance` L91–103
- `ps_monthly_prorate` L106–115
- `ps_attendance_suggestion` L121–129
- `ps_monthly_inputs` L131–138
- `ps_monthly_pick_tier` L140–146
- `ps_sales_package_calc` L155–182

## includes/monthly/types/attendance_bonus_1.php (4 行, 0.1 KB)


## includes/monthly/types/attendance_bonus_2.php (3 行, 0.2 KB)


## includes/monthly/types/base_fee_1.php (5 行, 0.2 KB)


## includes/monthly/types/base_fee_2.php (6 行, 0.5 KB)


## includes/monthly/types/dept_share.php (33 行, 2.5 KB)


## includes/monthly/types/fixed.php (6 行, 0.5 KB)


## includes/monthly/types/legacy_module_1.php (6 行, 0.2 KB)


## includes/monthly/types/legacy_module_2.php (12 行, 0.5 KB)


## includes/monthly/types/legacy_module_3.php (6 行, 0.4 KB)


## includes/monthly/types/legacy_module_4.php (4 行, 0.2 KB)


## includes/monthly/types/legacy_sheet_1.php (6 行, 0.2 KB)


## includes/monthly/types/legacy_sheet_2.php (22 行, 1.1 KB)

- 加载：`require_once (dirname(__DIR__, 2)) . '/ProjectReviewPolicy.php';`

## includes/monthly/types/legacy_sheet_3.php (65 行, 4 KB)


## includes/monthly/types/manual.php (7 行, 0.3 KB)


## includes/monthly/types/order_count.php (14 行, 1 KB)


## includes/monthly/types/per_unit.php (9 行, 0.5 KB)


## includes/monthly/types/perf_rank.php (19 行, 1.5 KB)

- 加载：`require_once (dirname(__DIR__, 2)) . '/functions.php';`

## includes/monthly/types/profit_pool.php (39 行, 3 KB)


## includes/monthly/types/ranking.php (12 行, 0.7 KB)


## includes/monthly/types/ranking_manual.php (9 行, 0.5 KB)


## includes/monthly/types/sales_package_1.php (10 行, 0.6 KB)


## includes/monthly/types/sales_package_2.php (7 行, 0.6 KB)


## includes/monthly/types/threshold_bonus.php (9 行, 0.5 KB)


## includes/monthly/types/tier_rate.php (10 行, 0.7 KB)


## includes/order_credentials_card.php (88 行, 10.9 KB)

- 加载：`require_once __DIR__ . '/ProjectVault.php';`

## includes/order_renewal_card.php (11 行, 2 KB)

- 加载：`require_once __DIR__.'/ProjectRenewals.php';`

## includes/project_order_items_view.php (20 行, 3.8 KB)


## includes/refund_trash_action.php (9 行, 0.7 KB)


## includes/renewal_dashboard_card.php (15 行, 1.2 KB)

- 加载：`require_once __DIR__.'/ProjectRenewals.php';`

## includes/renewal_due_widget.php (126 行, 9.1 KB)

- `rdw_contacts` L11–24
- `rdw_due` L29–59
- 加载：`require_once __DIR__ . '/ProjectRenewals.php';`

## includes/renewal_info_popup.php (100 行, 7.7 KB)

- `rip_missing` L14–45
- 加载：`require_once __DIR__ . '/ProjectRenewals.php';`

## includes/renewal_nav.php (7 行, 0.3 KB)

- 加载：`require_once __DIR__.'/ProjectRenewals.php';`

## includes/review_policy_editor.php (25 行, 3.9 KB)

- 加载：`require_once __DIR__ . '/ProjectReviewPolicy.php';`

## includes/rule_algo_card.php (135 行, 16.5 KB)

- `ra_f` L8–8
- `ra_asset` L9–9
- 加载：`require_once __DIR__ . '/ProjectRuleAlgo.php';`

## includes/salary/CalcAttendanceRewardTrait.php (197 行, 7.7 KB)

- `CalcAttendanceRewardTrait` L2–196
- `CalcAttendanceRewardTrait::calcAttendanceFull` L4–86
- `CalcAttendanceRewardTrait::calcAttendanceDaily` L89–100
- `CalcAttendanceRewardTrait::calcAttendanceDeduct` L103–114
- `CalcAttendanceRewardTrait::calcCustomerReward` L117–194

## includes/salary/CalcBaseTrait.php (183 行, 7.5 KB)

- `CalcBaseTrait` L2–182
- `CalcBaseTrait::calcBaseSalary` L4–14
- `CalcBaseTrait::calcRefundDeduction` L17–152
- `CalcBaseTrait::calcBaseSalaryTiered` L155–180

## includes/salary/CalcMiniProgramTrait.php (133 行, 5.3 KB)

- `CalcMiniProgramTrait` L2–132
- `CalcMiniProgramTrait::calcMiniProgramCommission` L4–130

## includes/salary/CalcOrderCustomerTrait.php (30 行, 0.9 KB)

- `CalcOrderCustomerTrait` L2–29
- `CalcOrderCustomerTrait::extractWangwang` L4–27

## includes/salary/CalcOrderFilterTrait.php (90 行, 3.7 KB)

- `CalcOrderFilterTrait` L2–89
- `CalcOrderFilterTrait::filterOrderTotal` L4–44
- `CalcOrderFilterTrait::filterOrderCount` L47–87

## includes/salary/CalcPerOrderTrait.php (122 行, 5.8 KB)

- `CalcPerOrderTrait` L2–121
- `CalcPerOrderTrait::calcPerOrder` L4–119

## includes/salary/CalcPerformanceTrait.php (70 行, 3.4 KB)

- `CalcPerformanceTrait` L2–69
- `CalcPerformanceTrait::calcFixedSubsidy` L4–12
- `CalcPerformanceTrait::calcCsPerformance` L18–35
- `CalcPerformanceTrait::autoDeptPerf` L44–67

## includes/salary/CalcProfitTrait.php (49 行, 1.6 KB)

- `CalcProfitTrait` L2–48
- `CalcProfitTrait::calcProfitCommission` L4–46

## includes/salary/CalcReferralTrait.php (207 行, 10.3 KB)

- `CalcReferralTrait` L2–206
- `CalcReferralTrait::calcReferralOrder` L4–204

## includes/salary/CalcStandardTrait.php (253 行, 12.9 KB)

- `CalcStandardTrait` L2–252
- `CalcStandardTrait::calcStandard` L4–250

## includes/salary/CalcTieredTrait.php (200 行, 9.9 KB)

- `CalcTieredTrait` L2–199
- `CalcTieredTrait::calcTiered` L4–197

## includes/salary/CalcTrademarkTrait.php (102 行, 3.5 KB)

- `CalcTrademarkTrait` L2–101
- `CalcTrademarkTrait::calcTrademarkCommission` L4–55
- `CalcTrademarkTrait::calcTrademarkCashback` L58–99

## includes/salary/ModulesConfigTrait.php (158 行, 6.7 KB)

- `ModulesConfigTrait` L2–157
- `ModulesConfigTrait::saveModulesConfig` L4–126
- `ModulesConfigTrait::readModulesConfig` L131–142
- `ModulesConfigTrait::deleteCustomConfig` L147–155

## includes/salary/ModulesTypesTrait.php (220 行, 16.8 KB)

- `ModulesTypesTrait` L2–219
- `ModulesTypesTrait::getAvailableTypes` L4–217

## includes/settlement/actor_auth.php (118 行, 7.5 KB)

- `ps_actor` L4–14
- `ps_governance_has_business` L17–27
- `ps_require_actor` L29–75
- `ps_require_finance` L77–82
- `ps_csrf_token` L84–88
- `ps_check_csrf` L90–93
- `ps_order` L95–117
- 加载：`require_once __DIR__ . '/../ProjectAccountRoles.php';`

## includes/settlement/adjustment.php (181 行, 13.3 KB)

- `ps_next_open_month` L4–14
- `ps_post_adjustment` L21–107
- `ps_reclassify_order_kind` L110–178

## includes/settlement/approval.php (90 行, 7 KB)

- `ps_approve_order` L3–89
- 加载：`require_once (dirname(__DIR__, 1)) . '/ProjectSiteProjects.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/ProjectTrademarkCost.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/ProjectOrderItems.php';`

## includes/settlement/audit_db.php (45 行, 2.3 KB)

- `ps_audit` L3–7
- `ps_costs` L9–14
- `ps_cash_movements` L16–21
- `ps_recalculate_cash` L23–37
- `ps_participants` L39–44

## includes/settlement/backend.php (121 行, 6.3 KB)

- `ps_auto_finish_trade_success_orders` L8–15
- `ps_refund_later` L18–29
- `ps_order_asof` L32–38
- `ps_order_assign_backend` L46–120
- 加载：`require_once (dirname(__DIR__, 1)) . '/ProjectAutoReview.php';`

## includes/settlement/labels_todos.php (74 行, 4.4 KB)

- `ps_label` L4–16
- `ps_order_todos` L22–41
- `ps_mask_contact` L47–55
- `ps_mask_id` L58–66
- `ps_contact_for` L69–73

## includes/settlement/private_files.php (84 行, 3.7 KB)

- `ps_private_dir` L3–9
- `ps_private_store` L12–20
- `ps_private_read` L23–30
- `ps_private_delete` L33–39
- `ps_private_temp_copy` L42–49
- `ps_detect_mime` L55–63
- `ps_detect_file_mime` L65–72
- `ps_upload_proof` L74–83

## includes/settlement/requests.php (162 行, 7.4 KB)

- `ps_order_requests` L3–24
- `ps_order_pending_request` L26–41
- `ps_create_order_request` L43–75
- `ps_review_order_request` L77–161

## includes/settlement/rules.php (164 行, 10.1 KB)

- `ps_role_keys` L4–12
- `ps_rule_for` L18–44
- `ps_rules_for_person` L50–69
- `ps_role_rule_order_kind` L72–80
- `ps_rule` L82–85
- `money_plain` L87–90
- `ps_calc_person` L100–147
- `ps_trademark_piece_calc` L153–163

## includes/settlement/summary.php (304 行, 18.3 KB)

- `ps_summary` L3–214
- `ps_group_share_cents` L220–242
- `ps_allocate_pool_cents` L244–255
- `ps_group_subsidy_cents` L258–275
- `ps_settlement_preview` L277–284
- `ps_technical_reconciliation_summary` L286–296

## includes/workbench-nav.php (4 行, 0.3 KB)


## index.php (164 行, 11.4 KB)

- 加载：`require_once __DIR__ . '/includes/auth.php';`
- 加载：`include __DIR__ . '/includes/header.php';`
- 加载：`require_once __DIR__ . '/includes/ProjectRenewals.php';`
- 加载：`include __DIR__ . '/includes/renewal_due_widget.php';`
- 加载：`include __DIR__ . '/includes/domain_missing_widget.php';`
- 加载：`include __DIR__ . '/includes/renewal_info_popup.php';`
- 加载：`include __DIR__ . '/includes/rule_algo_card.php';`
- 加载：`include __DIR__ . '/includes/footer.php';`

## insurance/index.php (157 行, 7.2 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include $configPath;`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## jobs/etmll_sync_auto.php (19 行, 1.3 KB)

- 加载：`require_once __DIR__ . '/../includes/etmll_push.php';`

## jobs/governance_penalty_sync.php (45 行, 2 KB)

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

## jobs/renewal_sms.php (9 行, 0.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRenewalSms.php';`

## login.php (140 行, 8.3 KB)

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

## migrations/apply_management_accounts.php (24 行, 1.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`


## migrations/apply_mini_custom_cs.php (11 行, 0.8 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectSettlement.php';`

## migrations/apply_project.php (54 行, 2.7 KB)

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

## migrations/import_contribution_history.php (55 行, 4.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`

## migrations/provision_governance_accounts.php (37 行, 2.1 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/provision_graphic_designer.php (39 行, 2.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## migrations/provision_vault_accounts.php (51 行, 3.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## migrations/repair_trademark_pricing.php (43 行, 3.9 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectTrademarkCost.php';`

## orders/edit.php (219 行, 9.6 KB)

- `_caiwu_valid_back` L89–98
- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/functions.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## orders/index.php (5 行, 0.3 KB)

- 加载：`require_once __DIR__ . '/index/helpers/applyOrderVerification.php';`
- 加载：`require_once __DIR__ . '/index/helpers/parseOrderDate.php';`
- 加载：`require_once __DIR__ . '/index/helpers/ensureProjectColumn.php';`
- 加载：`include __DIR__ . '/index/context.php';`
- 加载：`include __DIR__ . '/index/view.php';`

## orders/index/actions/batch_delete.php (26 行, 1.3 KB)


## orders/index/actions/delete.php (21 行, 1 KB)


## orders/index/actions/delete_group.php (40 行, 1.7 KB)


## orders/index/actions/delete_months.php (45 行, 2.4 KB)


## orders/index/actions/delete_project.php (48 行, 2.4 KB)


## orders/index/actions/dispatch.php (24 行, 1.6 KB)

- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/manual_add.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/upload.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/delete.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/batch_delete.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/delete_group.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/delete_months.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/delete_project.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/verify_status.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/verify_pending.php';`

## orders/index/actions/manual_add.php (22 行, 1.1 KB)


## orders/index/actions/upload.php (4 行, 0.2 KB)

- 加载：`include __DIR__ . '/upload/part_1.php';`
- 加载：`include __DIR__ . '/upload/part_2.php';`

## orders/index/actions/upload/parse_rows.php (4 行, 0.2 KB)

- 加载：`include __DIR__ . '/parse_rows/part_1.php';`
- 加载：`include __DIR__ . '/parse_rows/part_2.php';`

## orders/index/actions/upload/parse_rows/fields.php (189 行, 12.1 KB)

- 加载：`include $deptConfigFile;`
- 加载：`include $deptFeeFile) : [];`

## orders/index/actions/upload/parse_rows/part_1.php (3 行, 0 KB)


## orders/index/actions/upload/parse_rows/part_2.php (235 行, 15 KB)

- 加载：`include __DIR__ . '/fields.php';`

## orders/index/actions/upload/part_1.php (6 行, 0.2 KB)


## orders/index/actions/upload/part_2.php (5 行, 0.2 KB)

- 加载：`include __DIR__ . '/part_2/body.php';`

## orders/index/actions/upload/part_2/body.php (4 行, 0.2 KB)

- 加载：`include __DIR__ . '/parts/part_1.php';`
- 加载：`include __DIR__ . '/parts/part_2.php';`

## orders/index/actions/upload/part_2/parts/part_1.php (9 行, 0.4 KB)


## orders/index/actions/upload/part_2/parts/part_2.php (11 行, 0.7 KB)

- 加载：`include __DIR__ . '/../../parse_rows.php';`

## orders/index/actions/verify_pending.php (29 行, 1.5 KB)


## orders/index/actions/verify_status.php (71 行, 3.6 KB)


## orders/index/context.php (346 行, 16.4 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/SalaryCalculator.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../classes/SimpleXLSX.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/index/helpers/applyOrderVerification.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/index/actions/dispatch.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/index/helpers/parseOrderDate.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/index/helpers/ensureProjectColumn.php';`

## orders/index/helpers/applyOrderVerification.php (149 行, 7.8 KB)

- `applyOrderVerification` L2–148

## orders/index/helpers/ensureProjectColumn.php (87 行, 5.2 KB)

- `ensureProjectColumn` L2–86

## orders/index/helpers/parseOrderDate.php (35 行, 1.4 KB)

- `parseOrderDate` L2–34

## orders/index/view.php (4 行, 0.4 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`
- 加载：`include __DIR__ . '/view/section_2.php';`
- 加载：`include __DIR__ . '/view/section_3.php';`
- 加载：`include __DIR__ . '/view/section_4.php';`

## orders/index/view/js_1.php (1 行, 0.1 KB)


## orders/index/view/js_2.php (318 行, 12.8 KB)


## orders/index/view/js_3.php (67 行, 2.9 KB)


## orders/index/view/section_1.php (289 行, 22.2 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_1.php';`

## orders/index/view/section_2.php (230 行, 17.4 KB)


## orders/index/view/section_3.php (253 行, 22.2 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_2.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_3.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/css/orders_index_4.css';`

## orders/index/view/section_4.php (31 行, 1.7 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php';`

## orders/pending.php (329 行, 17.3 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/functions.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## orders/recycle.php (359 行, 18.4 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/functions.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## performance/index.php (2 行, 0.2 KB)

- 加载：`include __DIR__ . '/index/context.php';`
- 加载：`include __DIR__ . '/index/view.php';`

## performance/index/actions/dispatch.php (13 行, 0.8 KB)

- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/member_exclude.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/member_include.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/import.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/index/actions/upload_delete.php';`

## performance/index/actions/import.php (14 行, 0.9 KB)


## performance/index/actions/member_exclude.php (9 行, 0.3 KB)


## performance/index/actions/member_include.php (9 行, 0.3 KB)


## performance/index/actions/upload_delete.php (14 行, 0.6 KB)


## performance/index/context.php (120 行, 5.3 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/index/actions/dispatch.php';`

## performance/index/view.php (4 行, 0.2 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`

## performance/index/view/section_1.php (317 行, 19.9 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/performance_index_1.js';`

## performance/month.php (2 行, 0.2 KB)

- 加载：`include __DIR__ . '/month/context.php';`
- 加载：`include __DIR__ . '/month/view.php';`

## performance/month/actions/assign.php (39 行, 2.4 KB)


## performance/month/actions/delete_pending.php (6 行, 0.2 KB)


## performance/month/actions/dispatch.php (12 行, 0.6 KB)

- 加载：`include (dirname(__DIR__, 2)) . '/month/actions/save.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/month/actions/assign.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/month/actions/delete_pending.php';`

## performance/month/actions/save.php (34 行, 2 KB)


## performance/month/context.php (62 行, 2.7 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/month/actions/dispatch.php';`

## performance/month/view.php (4 行, 0.4 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`
- 加载：`include __DIR__ . '/view/section_2.php';`
- 加载：`include __DIR__ . '/view/section_3.php';`

## performance/month/view/section_1.php (105 行, 7.1 KB)


## performance/month/view/section_2.php (260 行, 21.4 KB)


## performance/month/view/section_3.php (50 行, 3.4 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php';`

## performance/schemes.php (454 行, 28.3 KB)

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

## project/contributions.php (267 行, 25 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/corrections.php (51 行, 5.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/commission_explain.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`require_once __DIR__ . '/../includes/correction_tabs.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/credentials.php (60 行, 4.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectVault.php';`

## project/dashboard.php (187 行, 19.1 KB)

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

## project/file_sheet_api.php (32 行, 2.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSheetEdit.php';`

## project/files.php (225 行, 27.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportResult.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSheetEdit.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/governance.php (199 行, 24.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/governance_election.php (70 行, 8.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/governance_evidence.php (25 行, 1.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`

## project/governance_ideas.php (295 行, 42.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/governance_rules.php (80 行, 12.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectGovernanceRuleAccess.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/holidays.php (53 行, 4.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/import.php (5 行, 0.4 KB)

- 加载：`include __DIR__ . '/import/01_context.php';`
- 加载：`include __DIR__ . '/import/preview_controller.php';`
- 加载：`include __DIR__ . '/import/06_followup.php';`
- 加载：`include __DIR__ . '/import/view.php';`

## project/import/01_context.php (83 行, 5.8 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectIntake.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderSplit.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderJoin.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectSiteProjects.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectTrademarkCost.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectBusiness.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectAiFallback.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectDepartmentImport.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectImportResult.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectImportClassification.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectSheetEdit.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../classes/SimpleXLSX.php';`

## project/import/02_read_sheets.php (164 行, 15 KB)


## project/import/03a_order_no_and_split.php (167 行, 17.3 KB)


## project/import/03b_date_amount_status.php (248 行, 24.9 KB)


## project/import/03c_people_resources.php (135 行, 14.4 KB)


## project/import/04_merge_items.php (32 行, 3.2 KB)


## project/import/04_merge_rows.php (105 行, 9 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderFix.php';`

## project/import/05_commit.php (4 行, 0.2 KB)

- 加载：`include __DIR__ . '/commit/part_1.php';`
- 加载：`include __DIR__ . '/commit/part_2.php';`

## project/import/06_followup.php (30 行, 2.4 KB)


## project/import/commit/part_1.php (57 行, 4.9 KB)


## project/import/commit/part_2.php (279 行, 29.2 KB)

- 加载：`require_once (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/dup_feedback.php';`
- 加载：`require_once (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/ProjectAutoReview.php';`
- 加载：`require_once (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/ProjectRenewalImport.php';`

## project/import/preview_controller.php (148 行, 13.9 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/import/02_read_sheets.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectRenewalImport.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/03a_order_no_and_split.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/03b_date_amount_status.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/03c_people_resources.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/04_merge_items.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/04_merge_rows.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/05_commit.php';`

## project/import/view.php (166 行, 25.7 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../../assets/css/project_import_1.css';`
- 加载：`include __DIR__ . '/view/render_15.php';`
- 加载：`include __DIR__ . '/../../assets/js/project_import_2.js';`
- 加载：`include __DIR__ . '/../../assets/js/project_import_3.js';`
- 加载：`include __DIR__ . '/view/render_fix_panel.php';`
- 加载：`include __DIR__ . '/view/render_62.php';`
- 加载：`include __DIR__ . '/view/render_63.php';`
- 加载：`include __DIR__ . '/../../assets/js/project_import_4.js';`
- 加载：`include __DIR__ . '/view/view/js_5.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/../includes/footer.php';`

## project/import/view/render_15.php (8 行, 1.3 KB)


## project/import/view/render_62.php (9 行, 2 KB)


## project/import/view/render_63.php (10 行, 1.6 KB)


## project/import/view/view/js_5.php (17 行, 1.7 KB)


## project/import_undo_api.php (26 行, 1.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectImportUndo.php';`

## project/index.php (100 行, 7.7 KB)

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
- 加载：`include __DIR__ . '/index/actions/delete.php';`
- 加载：`include __DIR__ . '/index/actions/bulk.php';`
- 加载：`include __DIR__ . '/index/actions/create.php';`
- 加载：`include __DIR__ . '/../includes/ProjectOrderList.php';`
- 加载：`include __DIR__ . '/index/view.php';`

## project/index/actions/bulk.php (77 行, 4.8 KB)


## project/index/actions/create.php (202 行, 18.6 KB)

- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectRenewalMath.php';`
- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectJointIntake.php';`
- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectTrademarkCost.php';`
- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectSheetEdit.php';`

## project/index/actions/delete.php (35 行, 2 KB)


## project/index/view.php (5 行, 0.5 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`
- 加载：`include __DIR__ . '/view/section_2.php';`
- 加载：`include __DIR__ . '/view/section_3.php';`

## project/index/view/js_1.php (15 行, 1 KB)


## project/index/view/js_2.php (25 行, 1.6 KB)


## project/index/view/js_4.php (137 行, 10.9 KB)


## project/index/view/js_5.php (42 行, 3.2 KB)


## project/index/view/section_1.php (15 行, 2.6 KB)


## project/index/view/section_2.php (148 行, 23.1 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/renewal_due_widget.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/domain_missing_widget.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/renewal_info_popup.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/finance_taobao_card.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/rule_algo_card.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_1.php';`

## project/index/view/section_3.php (120 行, 15.3 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_2.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_index_3.js';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_4.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_5.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php';`

## project/knowledge.php (38 行, 5.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_article.php (69 行, 10.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_categories.php (27 行, 2.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_costs.php (160 行, 15.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectCostRequests.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_integrations.php (76 行, 14.1 KB)

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

## project/knowledge_rules.php (14 行, 2.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/joint_settlement_rules.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_skill.php (172 行, 16 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeSkills.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_skill_import.php (116 行, 10.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeChatImport.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_skills.php (78 行, 8.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeSkills.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/knowledge_skills_export.php (58 行, 6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeSkills.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/lookup.php (29 行, 1.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`

## project/messages.php (30 行, 1.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/order.php (9 行, 0.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/../includes/commission_explain.php';`
- 加载：`include __DIR__ . '/order/context.php';`
- 加载：`include __DIR__ . '/order/view.php';`

## project/order/actions/cash/add_cash.php (17 行, 1.4 KB)


## project/order/actions/cash/review_cash.php (12 行, 0.8 KB)


## project/order/actions/cost/add_cost.php (35 行, 2.9 KB)


## project/order/actions/cost/confirm_resources.php (7 行, 0.5 KB)


## project/order/actions/cost/link_item_cost.php (6 行, 0.3 KB)


## project/order/actions/cost/review_cost.php (11 行, 0.8 KB)


## project/order/actions/cost/tm_cost.php (7 行, 0.5 KB)

- 加载：`require_once (dirname(__DIR__, 3)) . '/../includes/ProjectTrademarkCost.php';`

## project/order/actions/cost/void_cost.php (12 行, 0.7 KB)


## project/order/actions/delivery_upgrade/apply_delivery_completion.php (10 行, 0.4 KB)


## project/order/actions/delivery_upgrade/apply_product_upgrade.php (36 行, 1.9 KB)


## project/order/actions/delivery_upgrade/review_delivery_completion.php (8 行, 0.4 KB)


## project/order/actions/delivery_upgrade/review_product_upgrade.php (13 行, 0.7 KB)


## project/order/actions/dispatch.php (77 行, 6.6 KB)

- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/cost/link_item_cost.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/order_meta/save_customer_intake.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/order_meta/set_order_kind.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/participants/add_counterpart.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/participants/assign_backend.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/participants/claim_backend.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/order_meta/save_technical_details.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/order_meta/rename_order_no.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/order_meta/update_order.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/cost/confirm_resources.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/cash/add_cash.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/cash/review_cash.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/participants/add_participant.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/participants/remove_participant.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/cost/add_cost.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/cost/tm_cost.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/cost/review_cost.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/cost/void_cost.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/order_meta/post_adjustment.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/delivery_upgrade/apply_delivery_completion.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/delivery_upgrade/review_delivery_completion.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/delivery_upgrade/apply_product_upgrade.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/delivery_upgrade/review_product_upgrade.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/order_meta/submit_commission_correction.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/order_meta/approve_order.php';`
- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectAutoReview.php';`

## project/order/actions/order_meta/approve_order.php (5 行, 0.2 KB)


## project/order/actions/order_meta/post_adjustment.php (11 行, 0.8 KB)


## project/order/actions/order_meta/rename_order_no.php (5 行, 0.2 KB)

- 加载：`require_once (dirname(__DIR__, 3)) . '/../includes/ProjectOrderNo.php';`

## project/order/actions/order_meta/save_customer_intake.php (5 行, 0.2 KB)


## project/order/actions/order_meta/save_technical_details.php (13 行, 1.1 KB)


## project/order/actions/order_meta/set_order_kind.php (10 行, 0.7 KB)


## project/order/actions/order_meta/submit_commission_correction.php (23 行, 1.6 KB)


## project/order/actions/order_meta/update_order.php (13 行, 1.1 KB)


## project/order/actions/participants/add_counterpart.php (19 行, 1.6 KB)


## project/order/actions/participants/add_participant.php (18 行, 1.4 KB)


## project/order/actions/participants/assign_backend.php (6 行, 0.3 KB)


## project/order/actions/participants/claim_backend.php (4 行, 0.1 KB)


## project/order/actions/participants/remove_participant.php (9 行, 0.5 KB)


## project/order/context.php (141 行, 8 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/order/actions/dispatch.php';`

## project/order/view.php (4 行, 0.4 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`
- 加载：`include __DIR__ . '/view/section_2.php';`
- 加载：`include __DIR__ . '/view/section_3.php';`
- 加载：`include __DIR__ . '/view/section_4.php';`

## project/order/view/section_1.php (199 行, 21.9 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/auto_review_card.php';`

## project/order/view/section_2.php (210 行, 20 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_1.js';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/order_credentials_card.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/order_renewal_card.php';`
- 加载：`require (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/project_order_items_view.php';`

## project/order/view/section_3.php (185 行, 22.4 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_2.js';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_3.js';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_4.js';`

## project/order/view/section_4.php (6 行, 0.7 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php';`

## project/order_fix_api.php (26 行, 1.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectOrderFix.php';`

## project/order_fixes.php (50 行, 5.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectOrderFix.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`require_once __DIR__ . '/../includes/correction_tabs.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/payroll.php (310 行, 39.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/preview_as.php (59 行, 5.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/renewal_due_widget.php';`
- 加载：`include __DIR__ . '/../includes/rule_algo_card.php';`
- 加载：`include __DIR__ . '/../includes/kb_chat_examples_card.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/profile.php (74 行, 7.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/proof.php (43 行, 1.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## project/refund_trash.php (39 行, 4.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/refunds.php (179 行, 29.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRefundMatch.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/refund_trash_action.php';`
- 加载：`include __DIR__ . '/../includes/refund_trash_action.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/renewal_gaps.php (101 行, 9.8 KB)

- `rg_rows` L13–35
- 加载：`require_once __DIR__ . '/../includes/ProjectSheetEdit.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRenewals.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/renewal_sms.php (33 行, 6.3 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectRenewalSms.php';`
- 加载：`include __DIR__.'/../includes/header.php';`
- 加载：`include __DIR__.'/../includes/footer.php';`

## project/renewals.php (93 行, 15.8 KB)

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

## project/rule_requests.php (54 行, 6.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`require_once __DIR__ . '/../includes/correction_tabs.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/rules.php (420 行, 66.7 KB)

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

## project/settings.php (302 行, 48.1 KB)

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

## project/system.php (193 行, 21.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectAiFallback.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/vault.php (226 行, 24.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectVault.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/welfare.php (222 行, 29.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectWelfare.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/welfare_rules.php (15 行, 4.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectWelfare.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## salaries/query.php (228 行, 9.8 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/functions.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## salaries/settle.php (6 行, 0.4 KB)

- 加载：`require_once __DIR__ . '/settle/helpers/calcFullAttendanceBonus.php';`
- 加载：`require_once __DIR__ . '/settle/helpers/calcProratedBaseSalary.php';`
- 加载：`require_once __DIR__ . '/settle/helpers/applyProratedBaseSalary.php';`
- 加载：`require_once __DIR__ . '/settle/helpers/loadEmployeeOrdersWithDept.php';`
- 加载：`include __DIR__ . '/settle/context.php';`
- 加载：`include __DIR__ . '/settle/view.php';`

## salaries/settle/actions/dispatch.php (10 行, 0.4 KB)

- 加载：`include (dirname(__DIR__, 2)) . '/settle/actions/preview.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/settle/actions/settle.php';`

## salaries/settle/actions/preview.php (245 行, 13.4 KB)


## salaries/settle/actions/settle.php (169 行, 9.6 KB)


## salaries/settle/context.php (67 行, 3.5 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/SalaryCalculator.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/../config/insurance.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/../config/dept_config.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/settle/helpers/calcFullAttendanceBonus.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/settle/helpers/calcProratedBaseSalary.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/settle/helpers/applyProratedBaseSalary.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/settle/helpers/loadEmployeeOrdersWithDept.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/settle/actions/dispatch.php';`

## salaries/settle/helpers/applyProratedBaseSalary.php (50 行, 2.7 KB)

- `applyProratedBaseSalary` L2–49

## salaries/settle/helpers/calcFullAttendanceBonus.php (33 行, 1.2 KB)

- `calcFullAttendanceBonus` L2–32

## salaries/settle/helpers/calcProratedBaseSalary.php (35 行, 2.1 KB)

- `calcProratedBaseSalary` L2–34

## salaries/settle/helpers/loadEmployeeOrdersWithDept.php (103 行, 4.9 KB)

- `loadEmployeeOrdersWithDept` L2–102

## salaries/settle/view.php (4 行, 0.3 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`
- 加载：`include __DIR__ . '/view/section_2.php';`

## salaries/settle/view/js_1.php (83 行, 3.5 KB)


## salaries/settle/view/section_1.php (93 行, 6.2 KB)


## salaries/settle/view/section_2.php (267 行, 19.3 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/settle/view/js_1.php';`

## shops/etmll_sync.php (238 行, 15.8 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/etmll_sync.php';`
- 加载：`require_once __DIR__ . '/../includes/etmll_push.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## shops/index.php (257 行, 11.2 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## shops/upload.php (4 行, 0.3 KB)

- 加载：`require_once __DIR__ . '/upload/helpers/extract_order_status.php';`
- 加载：`require_once __DIR__ . '/upload/helpers/ensureShopColumn.php';`
- 加载：`include __DIR__ . '/upload/context.php';`
- 加载：`include __DIR__ . '/upload/view.php';`

## shops/upload/actions/batch_delete.php (19 行, 0.8 KB)


## shops/upload/actions/delete_month.php (16 行, 0.7 KB)


## shops/upload/actions/delete_order.php (11 行, 0.4 KB)


## shops/upload/actions/dispatch.php (17 行, 1 KB)

- 加载：`include (dirname(__DIR__, 2)) . '/upload/actions/delete_order.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/upload/actions/batch_delete.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/upload/actions/delete_month.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/upload/actions/manual_add.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/upload/actions/upload.php';`

## shops/upload/actions/manual_add.php (17 行, 0.7 KB)


## shops/upload/actions/upload.php (266 行, 18.6 KB)

- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectShopState.php';`

## shops/upload/context.php (99 行, 4.3 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/SalaryCalculator.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../classes/SimpleXLSX.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/upload/helpers/extract_order_status.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/upload/helpers/ensureShopColumn.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/upload/actions/dispatch.php';`

## shops/upload/helpers/ensureShopColumn.php (14 行, 0.4 KB)

- `ensureShopColumn` L2–13

## shops/upload/helpers/extract_order_status.php (17 行, 0.5 KB)

- `extract_order_status` L2–16

## shops/upload/view.php (4 行, 0.3 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`
- 加载：`include __DIR__ . '/view/section_2.php';`
- 加载：`include __DIR__ . '/view/section_3.php';`

## shops/upload/view/section_1.php (104 行, 6.7 KB)


## shops/upload/view/section_2.php (134 行, 10.8 KB)

- 加载：`include __DIR__ . '/section_2/content.php';`

## shops/upload/view/section_2/content.php (193 行, 17.6 KB)


## shops/upload/view/section_3.php (24 行, 0.9 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/shops_upload_1.js';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php';`

## tests/attendance_approval_smoke.php (45 行, 3.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../project/rules.php';`
- 加载：`include __DIR__ . '/../project/rules.php';`

## tests/bid_flow_202608.php (67 行, 5.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/custom_site_cs_smoke.php (44 行, 3.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/dept_orders_compare_202608.php (140 行, 9.4 KB)

- 加载：`require __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/designer_split_import_smoke.php (86 行, 8.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/etmll_sync_smoke.php (131 行, 11.9 KB)

- `etmll_check` L6–9
- 加载：`require_once __DIR__ . '/../includes/etmll_sync.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectShopState.php';`
- 加载：`include missing shop flows and source updates');`
- 加载：`include verified/new creation and exclude deleted/old orders');`

## tests/governance_contributions_smoke.php (111 行, 10.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../project/contributions.php';`
- 加载：`include __DIR__ . '/../project/governance.php';`
- 加载：`include __DIR__ . '/../project/settings.php';`
- 加载：`include __DIR__ . '/../project/settings.php';`

## tests/governance_reminders_smoke.php (125 行, 13 KB)

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

## tests/graphic_design_package_smoke.php (53 行, 4.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/import_failure_avoidance_smoke.php (105 行, 9.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/import_followup_smoke.php (69 行, 6.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../includes/import_followup_modal.php';`
- 加载：`include __DIR__ . '/../includes/import_followup_modal.php';`

## tests/import_join_smoke.php (145 行, 13.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/import_lenient_people_smoke.php (62 行, 5.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/import_replay_fixes_smoke.php (83 行, 8.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/knowledge_categories_unit.php (25 行, 1.8 KB)

- `kbc_check` L5–5
- `kbc_reject` L6–6
- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`

## tests/order_no_canonical_smoke.php (26 行, 1.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/project_algorithm_smoke.php (242 行, 21.7 KB)

- `check_algorithm` L7–10
- `algorithm_person` L12–17
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`

## tests/project_auto_review_etmll.php (21 行, 2.3 KB)

- `are_check` L4–4
- `are_map` L5–5
- 加载：`require_once __DIR__.'/../includes/etmll_sync.php';`

## tests/project_auto_review_storage.php (50 行, 7.7 KB)

- `ars_check` L6–6
- 加载：`require_once __DIR__.'/../includes/ProjectAutoReview.php';`

## tests/project_auto_review_unit.php (97 行, 14.1 KB)

- `ar_check` L5–5
- `ar_base` L6–12
- `ar_state` L13–13
- 加载：`require_once __DIR__.'/../includes/ProjectAutoReviewMath.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectBusiness.php';`

## tests/project_dashboard_order_links.php (32 行, 2.4 KB)

- `dashboard_link_check` L6–10
- 加载：`require_once __DIR__ . '/split_source.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';`

## tests/project_department_import_flow_smoke.php (52 行, 4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/project_department_import_smoke.php (67 行, 5 KB)

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

## tests/project_finance_smoke.php (140 行, 10.9 KB)

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

## tests/project_governance_visibility_smoke.php (24 行, 1.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`include __DIR__ . '/../project/governance_ideas.php';`

## tests/project_import_result_smoke.php (22 行, 2 KB)

- `ir_assert` L4–4
- 加载：`require_once __DIR__ . '/split_source.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportResult.php';`

## tests/project_import_visibility.php (38 行, 2.9 KB)

- `iv_list` L7–13
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/index.php';`

## tests/project_intake_smoke.php (274 行, 23.6 KB)

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

## tests/project_knowledge_storage.php (77 行, 8.6 KB)

- `kbs_check` L15–15
- `kbs_reject` L16–16
- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeConnectors.php';`

## tests/project_knowledge_unit.php (56 行, 5.7 KB)

- `kb_check` L5–8
- `kb_reject` L9–12
- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledgeConnectors.php';`

## tests/project_management_accounts_smoke.php (85 行, 7.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`
- 加载：`require_once __DIR__ . '/../includes/dup_feedback.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`require __DIR__ . '/../migrations/apply_management_accounts.php';`


## tests/project_management_import_smoke.php (58 行, 5.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`require __DIR__ . '/../migrations/apply_management_accounts.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/project_monthly_smoke.php (122 行, 10 KB)

- `check_monthly` L8–11
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/project_monthly_split_compare.php (38 行, 2 KB)

- 加载：`require_once $argv[2] . '/includes/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`

## tests/project_order_items_store_test.php (54 行, 4.1 KB)

- `item_check` L6–6
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/project_order_items_test.php (40 行, 3 KB)

- `check_item` L4–4
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderItems.php';`

## tests/project_order_source_smoke.php (91 行, 7.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/order.php';`

## tests/project_partner_dashboard_smoke.php (27 行, 1.9 KB)

- `check` L3–3
- 加载：`require_once __DIR__ . '/../includes/ProjectPartnerDashboard.php';`

## tests/project_payroll_reconcile_202608.php (192 行, 20.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/project_php_cost_smoke.php (61 行, 3.9 KB)

- `check_php` L7–10
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`

## tests/project_refund_import_smoke.php (131 行, 13.1 KB)

- `refund_check` L6–9
- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`include __DIR__ . '/../project/refunds.php';`

## tests/project_refund_trash_storage.php (61 行, 5.8 KB)

- `rt_check` L7–7
- `rt_denied` L8–8
- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectAutoReview.php';`

## tests/project_renewals_storage.php (73 行, 8.3 KB)

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

## tests/project_site_projects_unit.php (22 行, 1.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSiteProjects.php';`

## tests/project_welfare_smoke.php (78 行, 6.7 KB)

- `welfare_check` L3–3
- 加载：`require_once __DIR__ . '/../includes/ProjectWelfare.php';`
- 加载：`include __DIR__ . '/../project/welfare.php';`
- 加载：`include __DIR__ . '/../project/governance_election.php';`
- 加载：`include __DIR__ . '/../project/welfare.php';`

## tests/require_isolated_database.php (11 行, 0.5 KB)

- `require_isolated_test_database` L3–10

## tests/salary_monthly_module_entry.php (24 行, 1.4 KB)

- `get_attendance` L4–8
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`

## tests/sheet_editor_smoke.php (51 行, 3.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSheetEdit.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`

## tests/split_source.php (12 行, 0.5 KB)

- `split_test_source` L4–11
- 加载：`require_once __DIR__ . '/../tools/code_structure.php';`

## tests/test_dongxu_view.php (34 行, 1.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`

## tests/test_trademark_integration.php (134 行, 7.9 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`
- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`

## tests/trademark_cost_flow_smoke.php (114 行, 11.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/trademark_department_smoke.php (127 行, 6.3 KB)

- `check_eq` L11–15
- 加载：`require_once __DIR__ . '/../config/database.php';`
- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`

## tests/trademark_import_rows_smoke.php (65 行, 6.4 KB)

- `tm_check` L7–13
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/trademark_pricing_import_smoke.php (65 行, 9.1 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectTrademarkCost.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__.'/require_isolated_database.php';`
- 加载：`require __DIR__.'/../migrations/apply_management_accounts.php';`
- 加载：`include __DIR__.'/../project/import.php';`

## tests/trademark_pricing_smoke.php (105 行, 10.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`include __DIR__.'/../project/order/actions/cost/review_cost.php';`
- 加载：`include __DIR__.'/../project/order/actions/cost/review_cost.php';`

## tests/trademark_upload_flow_202608.php (81 行, 5.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/vault_smoke.php (72 行, 7.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectVault.php';`
- 加载：`include __DIR__ . '/../project/vault.php';`
- 加载：`include __DIR__ . '/../includes/order_credentials_card.php';`
- 加载：`include __DIR__ . '/../includes/order_credentials_card.php';`

## tests/website_delivery_and_upgrade_smoke.php (186 行, 11.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSystem.php';`

## tests/website_split_import_smoke.php (122 行, 11.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSheetEdit.php';`

## tests/wechat_writing_smoke.php (80 行, 6.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tools/audit_correct_website_business.php (63 行, 4.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportClassification.php';`

## tools/audit_dashboard_order_links.php (60 行, 4.4 KB)

- `audit_order_link_case` L12–30
- 加载：`require_once $root.'/includes/ProjectPartnerDashboard.php';`
- 加载：`require $root.'/project/index.php';`
- 加载：`require $root.'/project/dashboard.php';`

## tools/audit_dashboard_render.php (39 行, 2 KB)

- 加载：`require_once $root . '/includes/auth.php';`
- 加载：`require $root . '/project/dashboard.php';`

## tools/audit_expected_settlement.php (30 行, 2 KB)

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

## tools/dept_orders_switch_new_algo_202608.php (116 行, 6.7 KB)

- 加载：`require __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tools/gen_code_map.php (31 行, 1.5 KB)

- 加载：`require_once __DIR__ . '/code_structure.php';`
- 加载：`include . "`\n";`

## tools/import_amount_diff.php (98 行, 6.9 KB)

- `ir_preview` L16–34
- 加载：`require_once $root . '/includes/ProjectIntake.php';`
- 加载：`require_once $root . '/includes/ProjectSystem.php';`
- 加载：`require_once $root . '/includes/ProjectOrderJoin.php';`
- 加载：`include $root . '/project/import.php';`

## tools/import_reconcile.php (76 行, 5.9 KB)

- `ir_preview` L14–31
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSystem.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tools/import_replay.php (77 行, 5.5 KB)

- `replay_file` L20–52
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSystem.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tools/merge_duplicate_orders_20261008.php (76 行, 6.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tools/merge_same_sale_children.php (87 行, 6.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderJoin.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportUndo.php';`

## tools/recover_project_import.php (46 行, 3.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportResult.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSystem.php';`
- 加载：`require_once __DIR__ . '/../includes/dup_feedback.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tools/reflow_split.php (73 行, 3.4 KB)


## tools/repair_joint_customer_snapshots.php (59 行, 5.9 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectSettlement.php';`

## tools/repair_shared_payment_site_orders_20261008.php (150 行, 13.8 KB)

- `site3_cents` L29–29
- `site3_assert` L30–30
- `site3_rows` L31–33
- `site3_source` L34–38
- 加载：`require_once '/www/wwwroot/hezuoshang/includes/ProjectIntake.php';`
- 加载：`require_once '/www/wwwroot/hezuoshang/includes/ProjectOrderSplit.php';`
- 加载：`require_once '/www/wwwroot/hezuoshang/includes/ProjectSiteProjects.php';`
- 加载：`require_once '/www/wwwroot/hezuoshang/includes/ProjectAutoReview.php';`

## tools/repair_trademark_costs.php (5 行, 0.2 KB)

- 加载：`require __DIR__ . '/../migrations/repair_trademark_pricing.php';`

## tools/repair_website_order_items.php (67 行, 4.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tools/seed_trademark_costs.php (47 行, 2.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tools/split_runtime.php (26 行, 1.2 KB)

- 加载：`require_once $root . '/includes/' . $entry;`

## tools/verify_split.php (60 行, 3 KB)

- 加载：`require_once __DIR__ . '/code_structure.php';`

## workbench-entry.php (9 行, 0.4 KB)

- 加载：`require_once __DIR__ . '/includes/auth.php';`
- 加载：`require $file;`

## workbench-sso.php (57 行, 4 KB)

- `wb_fail` L6–6
- `wb_identity` L11–22
- 加载：`require $cfgFile;`
- 加载：`require_once __DIR__ . '/includes/auth.php';`


