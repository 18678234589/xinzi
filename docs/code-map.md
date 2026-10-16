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


## api对接/商务中国/moded/client/php/corpmailreg.php (132 行, 3.1 KB)


## api对接/商务中国/moded/client/php/domaincheck.php (236 行, 6.5 KB)


## api对接/商务中国/moded/client/php/domainregister.php (1064 行, 33.1 KB)


## api对接/商务中国/moded/client/php/sample.php (46 行, 0.8 KB)


## api对接/商务中国/moded/client/php/vhostreg.php (133 行, 3.2 KB)


## api对接/商务中国/webservice-php_for_whmcs-demo/bizcn.isoap.class.php (5105 行, 251.1 KB)

- `bizcnsoap_base` L3–420
- `bizcnsoap_base::bizcnsoap_base` L17–19
- `bizcnsoap_base::getGlobalDebugLevel` L20–22
- `bizcnsoap_base::setGlobalDebugLevel` L23–25
- `bizcnsoap_base::getDebugLevel` L26–28
- `bizcnsoap_base::setDebugLevel` L29–31
- `bizcnsoap_base::debug` L32–36
- `bizcnsoap_base::appendDebug` L37–41
- `bizcnsoap_base::clearDebug` L42–44
- `bizcnsoap_base::getDebug` L45–47
- `bizcnsoap_base::getDebugAsXMLComment` L48–54
- `bizcnsoap_base::expandEntities` L55–64
- `bizcnsoap_base::getError` L65–70
- `bizcnsoap_base::setError` L71–73
- `bizcnsoap_base::isArraySimpleOrStruct` L74–82
- `bizcnsoap_base::serialize_val` L83–310
- `bizcnsoap_base::serializeEnvelope` L311–340
- `bizcnsoap_base::formatDump` L341–344
- `bizcnsoap_base::contractQname` L345–357
- `bizcnsoap_base::expandQname` L358–370
- `bizcnsoap_base::getLocalPart` L371–377
- `bizcnsoap_base::getPrefix` L378–383
- `bizcnsoap_base::getNamespaceFromPrefix` L384–389
- `bizcnsoap_base::getPrefixFromNamespace` L390–398
- `bizcnsoap_base::getmicrotime` L399–409
- `bizcnsoap_base::varDump` L410–416
- `bizcnsoap_base::__toString` L417–419
- `timestamp_to_iso8601` L421–441
- `iso8601_to_timestamp` L442–461
- `usleepWindows` L462–468
- `bizcnsoap_fault` L470–490
- `bizcnsoap_fault::bizcnsoap_fault` L475–481
- `bizcnsoap_fault::serialize` L482–489
- `soap_fault` L491–492
- `bizcnsoap_xmlschema` L494–1101
- `bizcnsoap_xmlschema::bizcnsoap_xmlschema` L517–532
- `bizcnsoap_xmlschema::parseFile` L533–549
- `bizcnsoap_xmlschema::parseString` L550–573
- `bizcnsoap_xmlschema::CreateTypeName` L574–580
- `bizcnsoap_xmlschema::schemaStartElement` L581–829
- `bizcnsoap_xmlschema::schemaEndElement` L830–854
- `bizcnsoap_xmlschema::schemaCharacterData` L855–858
- `bizcnsoap_xmlschema::serializeSchema` L859–955
- `bizcnsoap_xmlschema::xdebug` L956–958
- `bizcnsoap_xmlschema::getPHPType` L959–966
- `bizcnsoap_xmlschema::getTypeDef` L967–1031
- `bizcnsoap_xmlschema::serializeTypeDef` L1032–1055
- `bizcnsoap_xmlschema::typeToForm` L1056–1081
- `bizcnsoap_xmlschema::addComplexType` L1082–1086
- `bizcnsoap_xmlschema::addSimpleType` L1087–1091
- `bizcnsoap_xmlschema::addElement` L1092–1100
- `XMLSchema` L1102–1103
- `soapval` L1105–1127
- `soapval::soapval` L1112–1120
- `soapval::serialize` L1121–1123
- `soapval::decode` L1124–1126
- `soap_transport_http` L1129–1961
- `soap_transport_http::soap_transport_http` L1157–1168
- `soap_transport_http::setCurlOption` L1169–1173
- `soap_transport_http::setHeader` L1174–1177
- `soap_transport_http::unsetHeader` L1178–1183
- `soap_transport_http::setURL` L1184–1211
- `soap_transport_http::io_method` L1212–1216
- `soap_transport_http::connect` L1217–1378
- `soap_transport_http::send` L1379–1399
- `soap_transport_http::sendHTTPS` L1400–1402
- `soap_transport_http::setCredentials` L1403–1442
- `soap_transport_http::setSOAPAction` L1443–1445
- `soap_transport_http::setEncoding` L1446–1456
- `soap_transport_http::setProxy` L1457–1468
- `soap_transport_http::isSkippableCurlHeader` L1469–1476
- `soap_transport_http::decodeChunked` L1477–1510
- `soap_transport_http::buildPayload` L1511–1535
- `soap_transport_http::sendRequest` L1536–1573
- `soap_transport_http::getResponse` L1574–1867
- `soap_transport_http::setContentType` L1868–1870
- `soap_transport_http::usePersistentConnection` L1871–1879
- `soap_transport_http::parseCookie` L1880–1922
- `soap_transport_http::getCookiesForRequest` L1923–1960
- `bizcnsoap_server` L1963–2653
- `bizcnsoap_server::bizcnsoap_server` L1988–2038
- `bizcnsoap_server::service` L2039–2106
- `bizcnsoap_server::parse_http_headers` L2107–2201
- `bizcnsoap_server::parse_request` L2202–2228
- `bizcnsoap_server::invoke_method` L2229–2345
- `bizcnsoap_server::serialize_return` L2346–2423
- `bizcnsoap_server::send_response` L2424–2477
- `bizcnsoap_server::verify_method` L2478–2487
- `bizcnsoap_server::parseRequest` L2488–2526
- `bizcnsoap_server::getHTTPBody` L2527–2529
- `bizcnsoap_server::getHTTPContentType` L2530–2532
- `bizcnsoap_server::getHTTPContentTypeCharset` L2533–2535
- `bizcnsoap_server::add_to_map` L2536–2538
- `bizcnsoap_server::register` L2539–2588
- `bizcnsoap_server::fault` L2589–2595
- `bizcnsoap_server::configureWSDL` L2596–2652
- `soap_server` L2654–2655
- `wsdl` L2657–4077
- `wsdl::wsdl` L2693–2705
- `wsdl::fetchWSDL` L2706–2786
- `wsdl::parseWSDL` L2787–2857
- `wsdl::start_element` L2858–3019
- `wsdl::end_element` L3020–3036
- `wsdl::character_data` L3037–3045
- `wsdl::setCredentials` L3046–3053
- `wsdl::getBindingData` L3054–3058
- `wsdl::getOperations` L3059–3084
- `wsdl::getOperationData` L3085–3101
- `wsdl::getOperationDataForSoapAction` L3102–3117
- `wsdl::getTypeDef` L3118–3170
- `wsdl::webDescription` L3171–3291
- `wsdl::serialize` L3292–3411
- `wsdl::parametersMatchWrapped` L3412–3464
- `wsdl::serializeRPCParameters` L3465–3538
- `wsdl::serializeParameters` L3539–3593
- `wsdl::serializeType` L3594–3864
- `wsdl::serializeComplexTypeAttributes` L3865–3914
- `wsdl::serializeComplexTypeElements` L3915–3994
- `wsdl::addComplexType` L3995–4024
- `wsdl::addSimpleType` L4025–4029
- `wsdl::addElement` L4030–4033
- `wsdl::addOperation` L4034–4076
- `bizcnsoap_parser` L4079–4500
- `bizcnsoap_parser::bizcnsoap_parser` L4108–4175
- `bizcnsoap_parser::start_element` L4176–4282
- `bizcnsoap_parser::end_element` L4283–4349
- `bizcnsoap_parser::character_data` L4350–4363
- `bizcnsoap_parser::get_response` L4364–4366
- `bizcnsoap_parser::get_soapbody` L4367–4369
- `bizcnsoap_parser::get_soapheader` L4370–4372
- `bizcnsoap_parser::getHeaders` L4373–4375
- `bizcnsoap_parser::decodeSimple` L4376–4403
- `bizcnsoap_parser::buildVal` L4404–4499
- `soap_parser` L4501–4502
- `bizcnsoap_client` L4504–5100
- `bizcnsoap_client::bizcnsoap_client` L4540–4569
- `bizcnsoap_client::call` L4570–4716
- `bizcnsoap_client::checkWSDL` L4718–4744
- `bizcnsoap_client::loadWSDL` L4745–4751
- `bizcnsoap_client::getOperationData` L4752–4761
- `bizcnsoap_client::send` L4762–4817
- `bizcnsoap_client::parseResponse` L4818–4855
- `bizcnsoap_client::setCurlOption` L4856–4860
- `bizcnsoap_client::setEndpoint` L4861–4864
- `bizcnsoap_client::setHeaders` L4865–4869
- `bizcnsoap_client::getHeaders` L4870–4872
- `bizcnsoap_client::getHeader` L4873–4875
- `bizcnsoap_client::setHTTPProxy` L4876–4881
- `bizcnsoap_client::setCredentials` L4882–4889
- `bizcnsoap_client::setHTTPEncoding` L4890–4893
- `bizcnsoap_client::setUseCURL` L4894–4897
- `bizcnsoap_client::useHTTPPersistentConnection` L4898–4901
- `bizcnsoap_client::getDefaultRpcParams` L4902–4904
- `bizcnsoap_client::setDefaultRpcParams` L4905–4907
- `bizcnsoap_client::getProxy` L4908–4943
- `bizcnsoap_client::_getProxyClassCode` L4944–4994
- `bizcnsoap_client::getProxyClassCode` L4995–4998
- `bizcnsoap_client::getHTTPBody` L4999–5001
- `bizcnsoap_client::getHTTPContentType` L5002–5004
- `bizcnsoap_client::getHTTPContentTypeCharset` L5005–5007
- `bizcnsoap_client::decodeUTF8` L5008–5011
- `bizcnsoap_client::setCookie` L5012–5018
- `bizcnsoap_client::getCookies` L5019–5021
- `bizcnsoap_client::checkCookies` L5022–5046
- `bizcnsoap_client::UpdateCookies` L5047–5099
- `soapclient` L5102–5103
- 加载：`include into namespace ' . $this -> schemaTargetNamespace . ' from ' . $attrs['schemaLocation']);`
- 加载：`include without schemaLocation attribute');`
- 加载：`include cURL or change php.ini to load the PHP cURL extension.');`

## api对接/商务中国/webservice-php_for_whmcs-demo/bizcn.php (1394 行, 38 KB)

- `wsdl` L6–187
- `return_process` L189–201
- `bizcn_domainCheck` L203–231
- `bizcn_getConfigArray` L234–247
- `bizcn_GetNameservers` L250–290
- `bizcn_SaveNameservers` L293–346
- `bizcn_GetRegistrarLock` L350–388
- `bizcn_SaveRegistrarLock` L392–430
- `bizcn_GetEmailForwarding` L433–446
- `bizcn_SaveEmailForwarding` L449–461
- `bizcn_GetDNS` L464–518
- `bizcn_SaveDNS` L521–578
- `bizcn_RegisterDomain` L581–700
- `bizcn_TransferDomain` L704–787
- `bizcn_RenewDomain` L790–822
- `bizcn_GetContactDetails` L825–915
- `bizcn_SaveContactDetails` L918–1124
- `bizcn_GetEPPCode` L1127–1159
- `bizcn_RegisterNameserver` L1161–1201
- `bizcn_ModifyNameserver` L1204–1249
- `bizcn_DeleteNameserver` L1252–1286
- `bizcn_infoDomainWhois` L1320–1353
- 加载：`include 'bizcn.isoap.class.php';`

## attendance/index.php (171 行, 8.2 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## attendance/month.php (2 行, 0.2 KB)

- 加载：`include __DIR__ . '/month/context.php';`
- 加载：`include __DIR__ . '/month/view.php';`

## attendance/month/actions/batch_delete.php (16 行, 0.7 KB)


## attendance/month/actions/delete.php (8 行, 0.3 KB)


## attendance/month/actions/dispatch.php (36 行, 1.3 KB)

- 加载：`include __DIR__ . '/home_roster.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/month/actions/manual_add.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/month/actions/delete.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/month/actions/batch_delete.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/month/actions/upload.php';`

## attendance/month/actions/home_roster.php (31 行, 1.6 KB)


## attendance/month/actions/manual_add.php (30 行, 1.8 KB)


## attendance/month/actions/upload.php (301 行, 22.3 KB)

- 加载：`require_once __DIR__ . '/../../../includes/ProjectMonthly.php';`

## attendance/month/context.php (47 行, 1.6 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../classes/SimpleXLSX.php';`
- 加载：`require_once __DIR__ . '/../../includes/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/../../includes/AttendanceHomeWork.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/month/actions/dispatch.php';`

## attendance/month/view.php (4 行, 0.2 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`

## attendance/month/view/home_roster.php (33 行, 3 KB)


## attendance/month/view/js_1.php (57 行, 2.7 KB)


## attendance/month/view/section_1.php (228 行, 15.7 KB)

- 加载：`include __DIR__ . '/home_roster.php';`
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

## forgot_password.php (53 行, 5.2 KB)

- 加载：`require_once __DIR__.'/includes/RecoveryWeb.php';`

## harness/cloud-tools.php (27 行, 1.7 KB)

- 加载：`require_once __DIR__ . '/../includes/AgentCloudTools.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnDomains.php';`

## harness/config.example.php (11 行, 0.6 KB)


## harness/dispatcher.php (26 行, 1.3 KB)

- 加载：`require_once __DIR__.'/../includes/auth.php';`

## harness/entry.php (10 行, 0.6 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require $cfgFile;`

## harness/sso.php (58 行, 4.1 KB)

- `hn_fail` L7–7
- `hn_identity` L13–24
- 加载：`require $cfgFile;`
- 加载：`require_once __DIR__ . '/../includes/auth.php';`

## includes/AgentCloudTools.php (179 行, 12.2 KB)

- `ac_signature` L7–10
- `ac_authenticate` L12–18
- `ac_ticket` L20–25
- `ac_ticket_read` L27–35
- `ac_record_version` L37–41
- `ac_dns_plan` L43–69
- `ac_dns_commit` L71–101
- `ac_once` L104–139
- `ac_dispatch` L141–178
- 加载：`require_once __DIR__ . '/QiniuCdn.php';`

## includes/AttendanceHomeWork.php (70 行, 3.1 KB)

- `attendance_home_schema` L3–9
- `attendance_home_ids` L11–21
- `attendance_require_draft` L23–28
- `attendance_home_match` L30–48
- `attendance_home_save` L50–69

## includes/AuthPassword.php (35 行, 2 KB)

- `auth_password_verify` L3–8
- `auth_password_is_strong` L10–14
- `auth_password_validate` L15–20
- `auth_session_validate` L21–34

## includes/AuthStaff.php (44 行, 2.1 KB)

- `auth_staff_pinyin` L3–14
- `auth_staff_unique_username` L16–23
- `auth_staff_needs_password_change` L25–28
- `auth_staff_destination` L30–37
- `auth_staff_login_seen` L39–43

## includes/AuthThrottle.php (93 行, 3.9 KB)

- `auth_client_ip` L15–25
- `auth_throttle_keys` L27–36
- `auth_login_locked` L39–52
- `auth_login_failed` L58–69
- `auth_login_succeeded` L72–79
- `auth_password_upgrade` L85–92

## includes/BizcnDnsRecords.php (58 行, 4.5 KB)

- `bd_record_replace` L11–57
- 加载：`require_once __DIR__ . '/BizcnDomainValues.php';`

## includes/BizcnDnsRouting.php (113 行, 6.2 KB)

- `bdr_nameservers` L5–10
- `bdr_provider` L12–19
- `bdr_context` L21–61
- `bdr_revision` L63–68
- `bdr_records` L70–86
- `bdr_save` L88–112
- 加载：`require_once __DIR__.'/DnsProviders.php';`

## includes/BizcnDomainAggregator.php (446 行, 17.7 KB)

- `bda_cache_file` L13–18
- `bda_clear_cache` L20–24
- `bda_get_dns_domains` L26–79
- `bda_get_renewal_domains` L81–169
- `bda_find_domain` L171–201
- `bda_unified_list` L203–445
- 加载：`require_once __DIR__ . '/BizcnDomainValues.php';`
- 加载：`require_once __DIR__ . '/BizcnDomainCatalog.php';`
- 加载：`require_once __DIR__ . '/DnsProviders.php';`
- 加载：`require_once __DIR__ . '/BizcnDnsRouting.php';`

## includes/BizcnDomainCatalog.php (135 行, 10.8 KB)

- `bc_date` L3–11
- `bc_normalize_sheets` L13–44
- `bc_read_export` L46–68
- `bc_find` L70–76
- `bc_list` L78–96
- `bc_import` L98–134
- 加载：`require_once __DIR__.'/../classes/SimpleXLSX.php';`

## includes/BizcnDomainStatus.php (233 行, 14.6 KB)

- `bs_categories` L7–20
- `bs_expiry_expr` L21–21
- `bs_condition` L23–40
- `bs_dates` L41–46
- `bs_from` L47–47
- `bs_counts` L50–67
- `bs_row_tags` L70–86
- `bs_decorate` L87–117
- `bs_list` L120–143
- `bs_store` L147–153
- `bs_fetch` L155–176
- `bs_pending` L180–185
- `bs_sync` L186–201
- `bs_list_action` L204–232

## includes/BizcnDomainValues.php (110 行, 6.5 KB)

- `bd_policy` L3–11
- `bd_domain` L12–18
- `bd_xml` L20–30
- `bd_node` L32–44
- `bd_parse` L46–70
- `bd_ns` L72–78
- `bd_rows` L80–84
- `bd_record` L86–109

## includes/BizcnDomains.php (70 行, 4.4 KB)

- `bd_config` L11–18
- `bd_can_access` L20–31
- `bd_call` L33–38
- `bd_transport` L41–51
- `bd_list` L53–56
- `bd_require_owned` L58–69
- 加载：`require_once __DIR__ . '/ProjectVault.php';`
- 加载：`require_once __DIR__ . '/BizcnDomainValues.php';`
- 加载：`require_once __DIR__ . '/BizcnDnsRecords.php';`
- 加载：`require_once __DIR__ . '/BizcnDomainCatalog.php';`
- 加载：`require_once __DIR__ . '/BizcnDomainAggregator.php';`
- 加载：`require_once __DIR__ . '/BizcnDomainStatus.php';`
- 加载：`require_once __DIR__ . '/BizcnOrderDomains.php';`
- 加载：`include $file : [];`

## includes/BizcnOrderDomains.php (223 行, 13 KB)

- `bo_provider_labels` L7–10
- `bo_extract_domain` L11–15
- `bo_auto_links` L16–36
- `bo_rebuild` L38–57
- `bo_ns_refresh` L59–73
- `bo_employee_id` L77–80
- `bo_can_manage_order` L81–89
- `bo_find_order` L90–99
- `bo_order_for_actor` L101–106
- `bo_link` L107–115
- `bo_orders_for` L116–124
- `bo_action` L125–139
- `bo_expiry_expr` L143–143
- `bo_from` L144–153
- `bo_search_where` L154–161
- `bo_my_counts` L162–179
- `bo_my_list` L180–215
- `bo_mine_total` L216–222

## includes/BizcnPostcodes.php (40 行, 10 KB)

- `bt_postcodes` L3–39

## includes/BizcnRegions.php (40 行, 9.4 KB)

- `bt_regions` L3–39

## includes/BizcnRegistration.php (279 行, 24.5 KB)

- `br_template_pass` L6–14
- `br_terms` L16–16
- `br_tld_rules` L17–24
- `br_match_tld_rule` L25–33
- `br_term_total_cents` L34–41
- `br_price` L42–71
- `br_public` L72–76
- `br_catalog_registered` L77–88
- `br_owner_label` L89–92
- `br_sync_templates_page` L93–100
- `br_directory_template` L101–106
- `br_dispatch` L107–278
- 加载：`require_once __DIR__.'/BizcnRegistrationStore.php';`
- 加载：`require_once __DIR__.'/BizcnTemplateAi.php';`
- 加载：`require_once __DIR__.'/BizcnOrderDomains.php';`

## includes/BizcnRegistrationStore.php (106 行, 9.8 KB)

- `br_actor_key` L3–3
- `br_default_ns` L5–5
- `br_admin` L6–6
- `br_day` L7–7
- `br_settings` L8–15
- `br_lock_settings` L16–21
- `br_usage` L22–26
- `br_save_settings` L27–36
- `br_quote_save` L37–42
- `br_request` L43–47
- `br_reserve` L48–65
- `br_mark_started` L66–70
- `br_state` L71–77
- `br_record_result` L78–93
- `br_release_unknown` L94–105

## includes/BizcnTemplateAi.php (397 行, 23.9 KB)

- `bt_person_cert_types` L10–13
- `bt_company_cert_types` L14–17
- `bt_id_card_valid` L21–28
- `bt_credit_code_valid` L29–37
- `bt_text_hints` L38–50
- `bt_city_stem` L54–57
- `bt_match_region` L58–84
- `bt_city_postcode` L86–91
- `bt_phone` L92–100
- `bt_ascii` L101–105
- `bt_ai_prompt` L109–122
- `bt_ai_extract` L123–128
- `bt_merge_fields` L134–186
- `bt_is_upload` L190–193
- `bt_upload_paths` L194–208
- `bt_load_image` L209–217
- `bt_resize_width` L219–228
- `bt_jpeg` L229–234
- `bt_ai_blob` L236–244
- `bt_certificate_jpeg` L246–268
- `bt_contact` L272–278
- `bt_validate_fields` L279–315
- `bt_template_args` L316–331
- `bt_action_ai` L335–364
- `bt_action_create` L365–378
- `bt_action_status` L379–387
- `bt_action_contact` L388–396
- 加载：`require_once __DIR__ . '/BizcnRegions.php';`
- 加载：`require_once __DIR__ . '/BizcnPostcodes.php';`

## includes/DnsProviders.php (371 行, 20.4 KB)

- `dns_cdn_prefill` L16–29
- `DnsApiError` L31–35
- `dns_provider_configured` L37–44
- `dns_cf_http` L46–56
- `dns_call` L62–84
- `dns_zones` L89–110
- `dns_zone_find` L112–116
- `dns_zone_ns` L119–124
- `dns_trim_dot` L126–129
- `dns_validate_domain` L132–141
- `dns_create_zone` L144–171
- `dns_public_ns` L175–182
- `dns_ns_resolver` L185–195
- `dns_relative_name` L199–205
- `dns_records` L207–243
- `dns_validate_record` L246–270
- `dns_dnspod_value` L272–275
- `dns_cf_payload` L277–283
- `dns_record_create` L285–297
- `dns_record_update` L299–310
- `dns_record_delete` L312–316
- `dns_normalize_bizcn_rows` L321–336
- `dns_record_key` L340–343
- `dns_copy_plan` L349–370
- 加载：`require_once __DIR__ . '/ProjectApiSettings.php';`
- 加载：`require_once __DIR__ . '/ProjectApiSettings.php';`
- 加载：`require_once __DIR__ . '/ProjectApiSettings.php';`
- 加载：`require_once __DIR__ . '/ProjectApiSettings.php';`

## includes/PasswordRecovery.php (115 行, 10.6 KB)

- `PasswordRecovery` L4–114
- `PasswordRecovery::__construct` L7–10
- `PasswordRecovery::now` L11–11
- `PasswordRecovery::hash` L12–12
- `PasswordRecovery::lock` L13–13
- `PasswordRecovery::row` L14–14
- `PasswordRecovery::run` L15–15
- `PasswordRecovery::table` L16–16
- `PasswordRecovery::account` L17–19
- `PasswordRecovery::boundPhone` L20–23
- `PasswordRecovery::send` L24–73
- `PasswordRecovery::finish` L74–113
- 加载：`require_once __DIR__.'/AuthPassword.php';`

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

## includes/ProjectAnnouncements.php (36 行, 1.6 KB)

- `pna_employee_id` L5–8
- `pna_pending` L10–20
- `pna_acknowledge` L23–35
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

## includes/ProjectAutoReview.php (276 行, 21.3 KB)

- `pa_storage_available` L10–18
- `pa_sources` L20–71
- `pa_context` L73–121
- `pa_evidence` L123–145
- `pa_record` L147–161
- `pa_retryable_database_error` L164–167
- `pa_check_order` L169–181
- `pa_check_order_once` L183–214
- `pa_batch` L216–247
- `pa_view` L249–262
- `pa_after_save` L265–275
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderSplit.php';`
- 加载：`require_once __DIR__ . '/ProjectSiteProjects.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundTrash.php';`
- 加载：`require_once __DIR__ . '/ProjectAutoReviewMath.php';`
- 加载：`require_once __DIR__ . '/ProjectReviewPolicy.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderItems.php';`

## includes/ProjectAutoReviewMath.php (220 行, 19.4 KB)

- `pa_cents` L7–15
- `pa_payment_evidence` L17–75
- `pa_evaluate` L77–207
- `pa_state_meta` L209–219
- 加载：`require_once __DIR__ . '/ProjectCommissionRoles.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`

## includes/ProjectBatchFill.php (288 行, 20.4 KB)

- `pbf_columns` L7–12
- `pbf_map` L13–26
- `pbf_parse_row` L28–61
- `pbf_parse_sheets` L62–85
- `pbf_scope` L86–89
- `pbf_order` L90–104
- `pbf_existing` L105–109
- `pbf_resource` L111–118
- `pbf_merge_value` L120–125
- `pbf_plan` L126–172
- `pbf_preview` L173–191
- `pbf_commit_row` L192–235
- `pbf_insert_resource` L236–245
- `pbf_missing_fields` L247–264
- `pbf_missing_orders` L265–287
- 加载：`require_once __DIR__.'/ProjectIntake.php';`
- 加载：`require_once __DIR__.'/ProjectRenewals.php';`

## includes/ProjectBatchReview.php (156 行, 11.2 KB)

- `pbr_ensure` L10–27
- `pbr_orders` L30–49
- `pbr_submit` L51–65
- `pbr_pending_count` L67–71
- `pbr_employees` L74–80
- `pbr_approve` L83–137
- `pbr_reject` L139–150
- `pbr_name` L152–155
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`
- 加载：`require_once __DIR__ . '/dup_feedback.php';`
- 加载：`require_once __DIR__ . '/dup_feedback.php';`

## includes/ProjectBusiness.php (438 行, 36.1 KB)

- `ps_business_catalog` L9–47
- `ps_business_account_products` L50–57
- `ps_business_fallback` L59–65
- `ps_business_normalize` L67–71
- `ps_is_website_order` L73–76
- `ps_business_requires_technical` L79–82
- `ps_business_service_fee_rate` L84–87
- `ps_business_order_kinds` L90–93
- `ps_order_kind_from_role` L96–100
- `ps_actor_businesses` L102–122
- `ps_business_choice` L124–130
- `ps_require_business` L132–138
- `ps_active_employee_for_business` L140–152
- `ps_business_details` L154–167
- `ps_save_business_details` L169–173
- `ps_order_kind_valid` L175–185
- `ps_business_import_columns` L191–314
- `ps_import_role_extras` L318–340
- `ps_business_import_headers` L343–348
- `ps_business_import_headers_base` L350–378
- `ps_import_header_norm` L385–390
- `ps_business_import_map` L392–406
- `ps_import_delivery_status` L411–423
- `ps_business_people_labels` L425–437
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectWriting.php';`
- 加载：`require_once __DIR__ . '/ProjectKindLabels.php';`
- 加载：`require_once __DIR__ . '/ProjectMiniappTemplate.php';`

## includes/ProjectCommissionRoles.php (36 行, 1.3 KB)

- `ps_role_keys` L2–10
- `ps_software_testing_role` L12–15
- `ps_group_weight_valid` L17–21
- `ps_software_testing_pick_business` L24–27
- `ps_software_tester_can_join` L30–35

## includes/ProjectCostDisplay.php (35 行, 1.6 KB)

- `ps_cost_display_state` L5–34
- 加载：`require_once __DIR__ . '/ProjectTrademarkCost.php';`

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

## includes/ProjectDeptHead.php (46 行, 2.1 KB)

- `pdh_departments` L8–21
- `pdh_order_condition` L24–29
- `pdh_can_view_order` L32–39
- `pdh_manages_person` L42–45

## includes/ProjectDupAppeal.php (164 行, 12.1 KB)

- `pda_ensure` L10–32
- `pda_row_key` L34–37
- `pda_approved_id` L40–48
- `pda_find_existing` L54–74
- `pda_find_existing_secondary` L80–89
- `pda_appeal_url` L92–95
- `pda_submit` L97–129
- `pda_handle` L131–147
- `pda_pending_count` L149–153
- `pda_list` L156–163
- 加载：`require_once __DIR__ . '/dup_feedback.php';`

## includes/ProjectExpectedSettlement.php (140 行, 8.9 KB)

- `ps_expected_snapshot_rows` L7–56
- `ps_expected_rollup` L59–83
- `ps_expected_month` L85–128
- `ps_partner_expected_income` L130–139
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundTrash.php';`

## includes/ProjectExtraRules.php (57 行, 2.9 KB)

- `pxr_supported` L12–20
- `pxr_ensure_column` L23–28
- `pxr_for_person` L31–56
- 加载：`require_once __DIR__ . '/ProjectCommissionRoles.php';`

## includes/ProjectFilingCost.php (44 行, 2.5 KB)

- `pfc_template` L9–13
- `pfc_applies` L16–19
- `pfc_apply` L22–29
- `pfc_supplement_cost` L32–43
- 加载：`require_once __DIR__ . '/ProjectTrademarkCost.php';`

## includes/ProjectFinanceAssignment.php (54 行, 2.6 KB)

- `ps_finance_username` L3–15
- `ps_finance_filter` L17–28
- `ps_finance_business_condition` L30–42
- `ps_finance_name` L44–53

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

## includes/ProjectImportClassification.php (65 行, 3.4 KB)

- `ps_import_website_business` L3–13
- `ps_import_filing_people` L19–31
- `ps_import_website_people_roles` L33–64

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

## includes/ProjectImportParse.php (214 行, 14.4 KB)

- `ps_import_date` L3–33
- `ps_import_names` L39–68
- `ps_import_split_joined_names` L71–87
- `ps_import_name_resembles_employee` L90–105
- `ps_import_names_lenient` L111–124
- `ps_import_autofill_details` L130–141
- `ps_import_fix_guide` L147–180
- `ps_import_followup_rows` L186–201
- `ps_import_public_transfer` L204–213
- 加载：`require_once __DIR__ . '/ShopOrderDate.php';`

## includes/ProjectImportReconciliation.php (44 行, 5.2 KB)

- `ps_payroll_import_reconciliation` L3–37
- `ps_payroll_import_reconciliation_render` L39–43
- 加载：`require_once __DIR__ . '/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`

## includes/ProjectImportResult.php (66 行, 4 KB)

- `ps_import_upload_actor` L3–13
- `ps_import_result_get` L15–20
- `ps_import_order_visible` L22–32
- `ps_import_result_save` L34–57
- `ps_import_numeric_summary` L60–65

## includes/ProjectImportUndo.php (149 行, 9.5 KB)

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

## includes/ProjectKindLabels.php (64 行, 2.5 KB)

- `pkl_map` L6–27
- `pkl_label` L30–34
- `pkl_kind_from_text` L37–43
- `pkl_choices` L46–55
- `pkl_js_map` L58–63

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

## includes/ProjectMiniappTemplate.php (362 行, 32.6 KB)

- `pmt_businesses` L9–12
- `pmt_columns` L15–38
- `pmt_writing_columns` L45–64
- `pmt_writing_examples` L66–74
- `pmt_filing_columns` L76–97
- `pmt_filing_examples` L99–107
- `pmt_web_columns` L110–141
- `pmt_web_examples` L143–155
- `pmt_headers` L157–160
- `pmt_example_rows` L163–178
- `pmt_col_letter` L180–185
- `pmt_xml` L187–190
- `pmt_serial` L193–198
- `pmt_zip` L201–212
- `pmt_cell` L214–219
- `pmt_xlsx` L222–345
- `pmt_normalize_term` L348–355
- `pmt_is_permanent_text` L358–361
- 加载：`require_once __DIR__ . '/ProjectTrademarkTemplate.php';`
- 加载：`require_once __DIR__ . '/ProjectTrademarkTemplate.php';`

## includes/ProjectMonthly.php (35 行, 3.8 KB)

- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/SalaryCalculator.php';`
- 加载：`require_once __DIR__ . '/ProjectOvertime.php';`
- 加载：`require_once __DIR__ . '/monthly/rules_data.php';`
- 加载：`require_once __DIR__ . '/monthly/legacy_orders.php';`
- 加载：`require_once __DIR__ . '/ProjectMonthlyResults.php';`
- 加载：`require_once __DIR__ . '/monthly/config_presets.php';`

## includes/ProjectMonthlyResults.php (234 行, 13.9 KB)

- `ps_monthly_results` L7–233
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
- 加载：`include __DIR__ . '/monthly/types/overtime_pay.php';`
- 加载：`include __DIR__ . '/monthly/types/attendance_bonus_1.php';`
- 加载：`include __DIR__ . '/monthly/types/attendance_bonus_2.php';`
- 加载：`include __DIR__ . '/monthly/types/manual.php';`
- 加载：`include __DIR__ . '/monthly/types/sales_package_1.php';`
- 加载：`include __DIR__ . '/monthly/types/sales_package_2.php';`
- 加载：`include __DIR__ . '/monthly/types/per_unit.php';`

## includes/ProjectOrderDelete.php (58 行, 4 KB)

- `pod_blocker` L12–50
- `pod_backup` L53–57
- 加载：`require_once __DIR__ . '/ProjectOrderTrash.php';`

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

## includes/ProjectOrderJoin.php (236 行, 15.9 KB)

- `poj_amount_value` L13–19
- `poj_same_sale_allowed` L22–37
- `poj_group_weights_equal` L40–47
- `poj_join_target` L50–66
- `poj_join_group` L69–86
- `poj_technician_choices` L89–100
- `poj_owner_can_correct_price` L114–129
- `poj_price_verdict` L131–148
- `poj_apply_price` L151–161
- `poj_overwrite_basics` L168–198
- `poj_replace_groups_from_sheet` L204–235
- 加载：`require_once __DIR__ . '/ProjectOrderSplit.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`

## includes/ProjectOrderList.php (213 行, 16.2 KB)

- 加载：`require_once __DIR__ . '/ProjectDeptHead.php';`
- 加载：`require_once __DIR__ . '/ProjectCostDisplay.php';`

## includes/ProjectOrderNo.php (51 行, 2.9 KB)

- `pon_is_internal` L8–11
- `pon_rename` L17–50
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`

## includes/ProjectOrderSource.php (267 行, 17.9 KB)

- `ps_source_record` L5–9
- `ps_payment_reference_order_no` L12–17
- `ps_customer_intake_blocking` L20–23
- `ps_customer_intake_conflicts` L25–42
- `ps_customer_intake_conflict_detail` L45–56
- `ps_save_customer_intake` L59–110
- `ps_source_nickname` L112–122
- `ps_shop_status_rank` L129–139
- `ps_shop_compatible` L145–151
- `ps_shop_channel` L157–164
- `ps_sync_project_status_latest` L167–179
- `ps_sync_project_from_shop_order` L181–215
- `ps_sync_existing_shop_order` L217–231
- `ps_shop_order_lookup` L237–266
- 加载：`require_once __DIR__ . '/ProjectWriting.php';`
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

## includes/ProjectOrderTrash.php (127 行, 7.8 KB)

- `pot_ensure` L10–38
- `pot_actor_name` L40–48
- `pot_trash` L51–72
- `pot_purge_expired` L75–81
- `pot_list` L84–93
- `pot_restore` L96–126

## includes/ProjectOvertime.php (122 行, 6.8 KB)

- `ot_default_holidays` L12–29
- `ot_holidays` L32–44
- `ot_holiday_keywords` L47–51
- `ot_parse_extra` L57–100
- `ot_split_cell` L103–110
- `ot_pay` L113–121

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

## includes/ProjectPayrollExtras.php (129 行, 8.5 KB)

- `ps_payroll_extras_ensure` L9–20
- `ps_insurance_settings` L23–29
- `ps_insurance_settings_save` L31–37
- `ps_insurance_suggest` L40–47
- `ps_payroll_extras` L53–80
- `ps_payroll_extras_action` L83–114
- `ps_payroll_extras_freeze` L117–128
- 加载：`include __DIR__ . '/../config/insurance.php';`

## includes/ProjectPayrollOrderSummary.php (89 行, 7.6 KB)

- `ps_payroll_order_summary` L3–58
- `ps_payroll_pending_reason_text` L60–70
- `ps_payroll_pieces_text` L72–75
- `ps_payroll_order_summary_render` L77–88

## includes/ProjectPresets.php (185 行, 20.9 KB)

- `ps_preset_cost_templates` L10–49
- `ps_preset_rules` L56–129
- `ps_preset_template_exists` L131–138
- `ps_preset_rule_exists` L140–155
- `ps_apply_preset_templates` L158–169
- `ps_apply_preset_rules` L172–184
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`

## includes/ProjectRefundClawback.php (81 行, 6.3 KB)

- `prc_commission_rows` L7–14
- `prc_money` L16–16
- `prc_rows_text` L18–25
- `prc_clawback_for_refund` L31–60
- `prc_backfill` L63–80
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectExpectedSettlement.php';`

## includes/ProjectRefundImport.php (433 行, 32.9 KB)

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
- `ps_refund_auto_apply` L377–410
- `ps_refund_pending_breakdown` L413–432
- 加载：`require_once __DIR__ . '/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/ProjectAiFallback.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundTrash.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundClawback.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundMatch.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`

## includes/ProjectRefundMatch.php (230 行, 17.7 KB)

- `prm_candidates` L5–12
- `prm_base` L14–17
- `prm_hint` L20–27
- `prm_pending_unmatched` L29–44
- `prm_resolve` L47–94
- `prm_auto_nickname` L97–112
- `prm_looks_like_order_no` L115–120
- `prm_auto_unreported` L129–145
- `prm_auto_reopen` L148–162
- `prm_waiting_upload` L165–183
- `prm_shop_refund_sync` L190–229
- 加载：`require_once __DIR__ . '/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundImport.php';`
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

## includes/ProjectRenewalFill.php (131 行, 10 KB)

- `prf_missing` L12–31
- `prf_set_domain_expiry` L34–49
- `prf_contact_split` L52–59
- `prf_save_order` L62–82
- `prf_card` L85–121
- `prf_assets` L124–130
- 加载：`require_once __DIR__ . '/ProjectSheetEdit.php';`
- 加载：`require_once __DIR__ . '/ProjectRenewals.php';`

## includes/ProjectRenewalImport.php (47 行, 4.5 KB)

- `pr_import_apply` L4–46
- 加载：`require_once __DIR__.'/ProjectRenewals.php';`
- 加载：`require_once __DIR__ . '/ProjectRenewalImportFields.php';`

## includes/ProjectRenewalImportFields.php (76 行, 5.6 KB)

- `pr_import_phone_value` L4–14
- `pr_import_fields` L16–64
- `pr_import_merge_fields` L67–75
- 加载：`require_once __DIR__ . '/ProjectRenewalMath.php';`

## includes/ProjectRenewalMath.php (97 行, 5.4 KB)

- `pr_date` L3–12
- `pr_today` L13–13
- `pr_default_expiry` L14–22
- `pr_days` L23–27
- `pr_reminder_day` L28–33
- `pr_phone` L34–43
- `pr_type_labels` L44–44
- `pr_types_for` L46–52
- `pr_wechat` L54–60
- `pr_has_contact` L62–65
- `pr_contact_missing_sql` L66–70
- `pr_owner_labels` L71–71
- `pr_default_type` L72–72
- `pr_import_label_type` L74–86
- `pr_policy_scope` L87–96

## includes/ProjectRenewalSms.php (126 行, 12 KB)

- `pr_sms_config` L4–7
- `pr_sms_map` L8–14
- `pr_sms_save_config` L15–35
- `pr_sms_request` L37–51
- `pr_sms_response` L52–59
- `pr_sms_send` L60–68
- `pr_sms_run` L70–125
- 加载：`require_once __DIR__ . '/ProjectRenewals.php';`

## includes/ProjectRenewals.php (165 行, 13.4 KB)

- `pr_ready` L6–12
- `pr_ensure_owner` L14–30
- `pr_set_owner` L36–51
- `pr_scope` L53–59
- `pr_require` L60–64
- `pr_is_super` L65–70
- `pr_order_where` L72–82
- `pr_order` L83–90
- `pr_seed` L91–99
- `pr_item` L100–105
- `pr_save` L106–157
- `pr_stats` L158–164
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

## includes/ProjectRuleAlgo.php (372 行, 24.8 KB)

- `pra_ensure` L13–46
- `pra_money` L48–48
- `pra_pct` L49–49
- `pra_formula` L55–82
- `pra_pairs` L85–105
- `pra_my_algorithms` L110–175
- `pra_monthly_text` L178–220
- `pra_my_fixed_income` L223–239
- `pra_actor_name` L243–250
- `pra_number` L252–257
- `pra_submit` L259–296
- `pra_attach` L299–323
- `pra_request_visible` L325–328
- `pra_my_requests` L330–336
- `pra_requests` L338–343
- `pra_pending_count` L345–349
- `pra_handle` L351–371
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/ProjectBusiness.php';`

## includes/ProjectRuleLink.php (140 行, 9.5 KB)

- `prl_scope` L11–30
- `prl_rule_text` L33–40
- `prl_info` L43–85
- `prl_apply` L88–139
- 加载：`require_once __DIR__ . '/ProjectExtraRules.php';`
- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`

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

## includes/ProjectSiteProjects.php (144 行, 9 KB)

- `psp_is_website` L4–7
- `psp_is_addon_program` L10–15
- `psp_key` L17–22
- `psp_child_no` L24–29
- `psp_import_auto_key` L32–40
- `psp_lookup` L42–47
- `psp_order` L49–54
- `psp_members` L56–64
- `psp_register` L66–77
- `psp_allocation_hash` L79–84
- `psp_validate_allocation` L86–102
- `psp_verify` L104–125
- `psp_verification` L127–132
- `psp_approve_guard` L134–143
- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`

## includes/ProjectSpotCheck.php (116 行, 7 KB)

- `psc_ensure` L11–27
- `psc_assign` L32–69
- `psc_notify` L72–86
- `psc_pending_count` L89–93
- `psc_pending_by_business` L96–100
- `psc_handle` L103–115
- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`
- 加载：`require_once __DIR__ . '/ProjectRefundMatch.php';`
- 加载：`require_once __DIR__ . '/ProjectSystem.php';`

## includes/ProjectSystem.php (279 行, 15.1 KB)

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
- `ps_business_default_reviewers` L242–250
- `ps_business_reviewers` L252–256
- `ps_business_reviewer` L259–264
- `ps_actor_can_review_business` L267–275
- 加载：`require_once __DIR__ . '/ProjectFinanceAssignment.php';`

## includes/ProjectTerms.php (81 行, 4.1 KB)

- `pt_terms` L21–33
- `pt_text` L36–39
- `pt_filter_html` L45–60
- `pt_output_filter` L63–71
- `pt_start` L74–80

## includes/ProjectTrademarkCost.php (188 行, 11 KB)

- `ptc_templates` L12–15
- `ptc_kind` L17–20
- `ptc_keyword` L23–26
- `ptc_detect_service` L29–35
- `ptc_order_has_costs` L37–42
- `ptc_count_label` L44–47
- `ptc_order_service` L50–56
- `ptc_customer_base` L59–62
- `ptc_cost_group` L71–103
- `ptc_cost_carried_by` L106–111
- `ptc_apply` L114–125
- `ptc_user_add` L128–155
- `ptc_backfill` L158–180
- `ptc_sync_order_cost` L183–187
- 加载：`require_once __DIR__ . '/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/ProjectTrademarkPricing.php';`
- 加载：`require_once __DIR__ . '/ProjectTrademarkReconcile.php';`

## includes/ProjectTrademarkDupCost.php (122 行, 8.1 KB)

- `ptd_source_text` L9–18
- `ptd_candidates` L21–62
- `ptd_count` L65–70
- `ptd_save_details` L72–76
- `ptd_decide` L79–121
- 加载：`require_once __DIR__ . '/ProjectTrademarkCost.php';`

## includes/ProjectTrademarkPricing.php (197 行, 16.9 KB)

- `ptc_service_names` L3–30
- `ptc_find_service` L32–39
- `ptc_mixed_plan` L42–71
- `ptc_cost_plan` L74–125
- `ptc_order_pricing_data` L128–134
- `ptc_import_check` L136–162
- `ptc_merge_import_details` L164–171
- `ptc_approval_guard` L174–196

## includes/ProjectTrademarkReconcile.php (107 行, 8.5 KB)

- `ptc_reconcile_order_cost` L3–106

## includes/ProjectTrademarkTemplate.php (115 行, 10.8 KB)

- `ptt_services` L4–7
- `ptt_columns` L10–36
- `ptt_examples` L38–48
- `ptt_notes` L50–59
- `ptt_is_professional_header` L61–65
- `ptt_prepare_record` L68–99
- `ptt_validate_price` L101–114
- 加载：`require_once __DIR__ . '/ProjectTrademarkCost.php';`

## includes/ProjectVault.php (375 行, 21.9 KB)

- `pv_key` L16–25
- `pv_encrypt` L27–36
- `pv_decrypt` L38–47
- `pv_access_list` L50–58
- `pv_admin_username` L60–65
- `pv_can_access` L67–73
- `pv_item_is_restricted` L76–79
- `pv_item_can_access` L81–89
- `pv_item_require` L91–99
- `pv_item_audit` L102–106
- `pv_item_reveal` L108–114
- `pv_item_delete` L116–121
- `pv_recent_logs` L123–138
- `pv_can_manage_access` L141–144
- `pv_password_ok` L147–150
- `pv_password_change_url` L152–155
- `pv_require_access` L157–162
- `pv_actor_name` L164–173
- `pv_guess_category` L176–182
- `pv_mask_secrets` L185–194
- `pv_unmask` L196–199
- `pv_parse_local` L202–233
- `pv_parse_paste` L239–281
- `pv_items` L283–294
- `pv_item_save` L296–323
- `pv_order_enabled` L326–329
- `pv_order_credentials` L331–336
- `pv_search_order_hits` L342–360
- `pv_order_credential_save` L362–374
- 加载：`require_once __DIR__ . '/ProjectSettlement.php';`
- 加载：`include $file : null;`

## includes/ProjectWebsiteRewards.php (70 行, 6.6 KB)

- `pwr_publish_october_notice` L5–69
- 加载：`require_once __DIR__ . '/ProjectAnnouncements.php';`

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

## includes/ProjectWriting.php (167 行, 11.5 KB)

- `psw_is_editor_order` L2–5
- `psw_is_member` L8–17
- `psw_source_from_row` L20–61
- `psw_people` L64–82
- `psw_participation_label` L84–96
- `psw_list_labels` L99–115
- `psw_change_kind` L118–166

## includes/QiniuCdn.php (176 行, 10.2 KB)

- `QiniuCdnError` L8–18
- `QiniuCdnError::__construct` L12–17
- `qc_call` L20–36
- `qc_domain` L38–41
- `qc_payload` L43–89
- `qc_public_domain` L91–102
- `qc_list` L104–110
- `qc_detail` L112–118
- `qc_certificates` L120–134
- `qc_certificate_matches` L136–145
- `qc_create` L147–161
- `qc_ticket_take` L163–170
- `qc_state_label` L172–175
- 加载：`require_once __DIR__ . '/DnsProviders.php';`
- 加载：`require_once __DIR__ . '/ProjectApiSettings.php';`

## includes/RecoverySms.php (36 行, 2.5 KB)

- `recovery_sms_request` L3–17
- `recovery_sms_response` L18–24
- `recovery_sms_send` L25–35

## includes/RecoveryWeb.php (39 行, 2.4 KB)

- `recovery_csrf` L6–10
- `recovery_check_csrf` L11–14
- `recovery_service` L15–24
- `recovery_head` L25–32
- `recovery_messages` L33–37
- `recovery_end` L38–38
- 加载：`require_once __DIR__.'/auth.php';`
- 加载：`require_once __DIR__.'/ProjectVault.php';`
- 加载：`require_once __DIR__.'/PasswordRecovery.php';`
- 加载：`require_once __DIR__.'/RecoverySms.php';`

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

## includes/ShopOrderDate.php (32 行, 2.2 KB)

- `sod_parse` L8–31

## includes/announcement_popup.php (21 行, 2.1 KB)

- 加载：`require_once __DIR__ . '/ProjectAnnouncements.php';`

## includes/auth.php (59 行, 1.6 KB)

- `is_logged_in` L31–34
- `require_login` L39–46
- `current_admin` L51–58
- 加载：`require_once __DIR__ . '/../config/database.php';`
- 加载：`require_once __DIR__ . '/functions.php';`
- 加载：`require_once __DIR__ . '/AuthPassword.php';`
- 加载：`require_once __DIR__ . '/AuthStaff.php';`
- 加载：`require_once __DIR__ . '/AuthThrottle.php';`

## includes/auto_review_card.php (25 行, 4.1 KB)

- 加载：`require_once __DIR__ . '/ProjectAutoReview.php';`

## includes/batch_review_nav.php (12 行, 0.8 KB)

- 加载：`require_once __DIR__ . '/ProjectBatchReview.php';`

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

## includes/correction_tabs.php (36 行, 3 KB)

- `pc_counts` L10–10
- `pc_pending_total` L11–11
- `pc_first_url` L13–18
- `pc_tabs` L19–34
- 加载：`require_once __DIR__ . '/commission_explain.php';`
- 加载：`require_once __DIR__ . '/ProjectRuleAlgo.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderFix.php';`
- 加载：`require_once __DIR__ . '/ProjectTrademarkDupCost.php';`

## includes/domain_missing_widget.php (64 行, 3.9 KB)

- `dmw_missing` L11–31
- 加载：`require_once __DIR__ . '/ProjectRenewals.php';`

## includes/dup_appeal_nav.php (18 行, 1 KB)

- 加载：`require_once __DIR__ . '/ProjectDupAppeal.php';`

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

## includes/etmll_bidirectional.php (20 行, 1 KB)

- `etmll_bidirectional_run` L4–19
- 加载：`require_once __DIR__ . '/etmll_push.php';`

## includes/etmll_push.php (134 行, 10.4 KB)

- `etmll_sync_cutoff` L6–6
- `etmll_push_shop_merchants` L7–12
- `etmll_push_since` L13–13
- `etmll_push_date` L14–19
- `etmll_push_local_source` L20–24
- `etmll_push_status_patch` L26–42
- `etmll_push_run` L43–51
- `etmll_push_orders` L53–126
- `etmll_push_status` L127–133
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


## includes/footer.php (25 行, 1.4 KB)

- 加载：`include __DIR__ . '/announcement_popup.php';`

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

## includes/header.php (234 行, 24.8 KB)

- 加载：`require_once __DIR__ . '/ProjectTerms.php';`
- 加载：`require_once __DIR__ . '/ProjectVault.php';`
- 加载：`require __DIR__ . '/workbench-nav.php';`
- 加载：`require_once __DIR__ . '/commission_explain.php';`
- 加载：`require_once __DIR__ . '/dup_feedback.php';`
- 加载：`require_once __DIR__ . '/ProjectCostRequests.php';`
- 加载：`require_once __DIR__ . '/ProjectSpotCheck.php';`
- 加载：`require_once __DIR__ . '/ProjectRuleAlgo.php';`
- 加载：`require_once __DIR__ . '/ProjectOrderFix.php';`
- 加载：`require_once __DIR__ . '/ProjectTrademarkDupCost.php';`
- 加载：`require_once __DIR__ . '/commission_explain.php';`
- 加载：`require_once __DIR__ . '/correction_tabs.php';`
- 加载：`require_once __DIR__ . '/dup_feedback.php';`
- 加载：`include __DIR__ . '/renewal_nav.php';`
- 加载：`include __DIR__ . '/service_perf_nav.php';`
- 加载：`include __DIR__ . '/dup_appeal_nav.php';`
- 加载：`include __DIR__ . '/batch_review_nav.php';`
- 加载：`include __DIR__ . '/import_followup_modal.php';`
- 加载：`include __DIR__ . '/spot_check_banner.php';`

## includes/import_followup_modal.php (59 行, 7.3 KB)


## includes/intake/followup.php (41 行, 2.6 KB)

- `ps_import_followup_save` L4–29
- `ps_import_followup_get` L32–40

## includes/intake/parse_business.php (109 行, 7.2 KB)

- `ps_import_domain_mode` L3–9
- `ps_import_kind_preference` L11–21
- `ps_import_kind_preference_save` L23–29
- `ps_import_business_signature` L32–36
- `ps_import_business_detect` L39–96
- `ps_import_business_preference_save` L99–106

## includes/intake/parse_rows.php (110 行, 7.7 KB)

- `ps_business_import_example_row` L4–26
- `ps_import_row_is_example` L29–33
- `ps_import_row_is_data` L36–40
- `ps_import_headerless_map` L46–96
- `ps_import_employee_index` L99–109
- 加载：`require_once dirname(__DIR__) . '/ProjectTrademarkTemplate.php';`
- 加载：`require_once __DIR__ . '/../ProjectRenewalImportFields.php';`

## includes/intake/participants_orders.php (165 行, 10 KB)

- `ps_employee_default_role` L4–11
- `ps_intake_participants` L13–49
- `ps_intake_domain_suggestion` L51–65
- `ps_order_no_canonical` L68–76
- `ps_order_no_resolve` L79–95
- `ps_import_group_taken` L98–103
- `ps_trademark_technical_role_open` L109–116
- `ps_trademark_add_technical` L119–129
- `ps_trademark_fix_row` L135–164

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

## includes/lib/attendance.php (210 行, 8.2 KB)

- `ensureAttendanceTable` L8–73
- `get_attendance_hidden_years` L78–85
- `hide_attendance_year` L90–93
- `get_attendance_custom_years` L98–105
- `add_attendance_custom_year` L110–115
- `get_attendance` L120–125
- `backfill_pending_attendance` L134–169
- `get_attendances_by_month` L174–183
- `get_attendance_years` L188–195
- `get_attendance_months` L200–209
- 加载：`require_once __DIR__ . '/../ProjectOvertime.php';`
- 加载：`require_once __DIR__ . '/../ProjectMonthly.php';`

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

## includes/lib/cs_perf_rank.php (301 行, 15.6 KB)

- `cs_perf_rank_detail` L9–65
- `cs_perf_rank_result` L77–120
- `cs_perf_rank_list` L126–193
- `get_cs_perf_target_suggestions` L202–251
- `detect_cs_perf_columns` L258–300
- 加载：`require_once __DIR__ . '/cs_perf_reception.php';`

## includes/lib/cs_perf_reception.php (345 行, 18.7 KB)

- `csr_metric_defs` L13–23
- `csr_ensure` L25–49
- `csr_params` L52–64
- `csr_params_save` L67–83
- `csr_metric_values` L86–98
- `csr_score_row` L101–116
- `csr_has_data` L118–125
- `csr_store_scores` L128–141
- `csr_header_norm` L143–146
- `csr_fraction` L149–158
- `csr_number` L160–166
- `csr_parse` L169–216
- `csr_employee_index` L219–231
- `csr_import` L234–256
- `csr_bind` L259–274
- `csr_delete_upload` L277–284
- `csr_month_rows` L287–297
- `csr_can_view` L304–309
- `csr_can_manage` L312–323
- `csr_employee_dept` L325–333
- `csr_is_design_head` L336–344
- 加载：`require_once dirname(__DIR__, 2) . '/classes/SimpleXLSX.php';`

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

## includes/lib/master_data.php (181 行, 4.8 KB)

- `json_response` L6–12
- `get_departments` L18–30
- `get_department_list` L35–43
- `get_department` L48–53
- `get_shops` L58–66
- `get_shop_list` L71–83
- `get_shop` L88–93
- `get_employees` L98–107
- `get_employee` L112–117
- `export_csv` L125–140
- `export_excel` L145–178

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

## includes/lib/order_text.php (191 行, 7.5 KB)

- `e` L9–16
- `clean_order_no` L25–28
- `extract_order_no` L30–60
- `extract_shop_from_raw` L67–91
- `match_shop_name` L101–159
- `ensureOrderNoColumn` L165–190

## includes/monthly/config_presets.php (4 行, 0.2 KB)

- 加载：`require_once __DIR__ . '/inputs_freeze.php';`
- 加载：`require_once __DIR__ . '/presets.php';`

## includes/monthly/inputs_freeze.php (113 行, 8.6 KB)

- `ps_monthly_freeze` L3–11
- `ps_monthly_params_from_input` L14–112

## includes/monthly/legacy_orders.php (100 行, 5.2 KB)

- `ps_legacy_sheet_orders` L10–99
- 加载：`require_once (dirname(__DIR__, 1)) . '/ProjectReviewPolicy.php';`

## includes/monthly/presets.php (160 行, 17.8 KB)

- `ps_monthly_presets` L7–113
- `ps_monthly_apply_presets` L115–159

## includes/monthly/rules_data.php (266 行, 15.4 KB)

- `ps_monthly_types` L3–23
- `ps_monthly_metrics` L25–28
- `ps_monthly_rules_for` L30–42
- `ps_monthly_scope_businesses` L44–48
- `ps_monthly_snapshot_matches` L50–58
- `ps_monthly_snapshots` L60–77
- `ps_monthly_snapshot_values` L83–92
- `ps_monthly_attendance` L95–114
- `ps_monthly_prorate` L117–126
- `ps_attendance_bonus_amount` L132–135
- `ps_attendance_work_from_home` L137–142
- `ps_attendance_row_work_from_home` L144–153
- `ps_monthly_ensure_attendance_rules` L156–186
- `ps_attendance_suggestion` L188–197
- `ps_monthly_inputs` L199–206
- `ps_monthly_pick_tier` L208–214
- `ps_unreviewed_orders` L220–229
- `ps_sales_package_calc` L238–265
- 加载：`require_once __DIR__ . '/../AttendanceHomeWork.php';`

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


## includes/monthly/types/overtime_pay.php (23 行, 1.6 KB)


## includes/monthly/types/per_unit.php (9 行, 0.5 KB)


## includes/monthly/types/perf_rank.php (19 行, 1.5 KB)

- 加载：`require_once (dirname(__DIR__, 2)) . '/functions.php';`

## includes/monthly/types/profit_pool.php (39 行, 3 KB)


## includes/monthly/types/ranking.php (12 行, 0.7 KB)


## includes/monthly/types/ranking_manual.php (31 行, 2 KB)


## includes/monthly/types/sales_package_1.php (10 行, 0.6 KB)


## includes/monthly/types/sales_package_2.php (10 行, 1.1 KB)


## includes/monthly/types/threshold_bonus.php (9 行, 0.5 KB)


## includes/monthly/types/tier_rate.php (10 行, 0.7 KB)


## includes/order_credentials_card.php (88 行, 10.9 KB)

- 加载：`require_once __DIR__ . '/ProjectVault.php';`

## includes/order_renewal_card.php (15 行, 2.7 KB)

- 加载：`require_once __DIR__.'/ProjectRenewals.php';`
- 加载：`require_once __DIR__.'/ProjectRenewalFill.php';`

## includes/project_order_items_view.php (20 行, 3.8 KB)


## includes/refund_trash_action.php (9 行, 0.7 KB)


## includes/renewal_dashboard_card.php (15 行, 1.2 KB)

- 加载：`require_once __DIR__.'/ProjectRenewals.php';`

## includes/renewal_due_widget.php (126 行, 9.1 KB)

- `rdw_contacts` L11–24
- `rdw_due` L29–59
- 加载：`require_once __DIR__ . '/ProjectRenewals.php';`

## includes/renewal_info_popup.php (64 行, 4.2 KB)

- `rip_missing` L14–24
- 加载：`require_once __DIR__ . '/ProjectRenewalFill.php';`

## includes/renewal_nav.php (8 行, 0.4 KB)

- 加载：`require_once __DIR__.'/ProjectRenewals.php';`

## includes/review_policy_editor.php (25 行, 3.9 KB)

- 加载：`require_once __DIR__ . '/ProjectReviewPolicy.php';`

## includes/rule_algo_card.php (141 行, 17 KB)

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

## includes/service_perf_nav.php (8 行, 0.4 KB)

- 加载：`require_once __DIR__ . '/lib/cs_perf_reception.php';`

## includes/settlement/actor_auth.php (141 行, 8.9 KB)

- `ps_actor` L5–10
- `ps_project_actor` L12–20
- `ps_actor_from_subject` L23–30
- `ps_governance_has_business` L33–43
- `ps_require_actor` L45–95
- `ps_require_finance` L97–102
- `ps_csrf_token` L104–108
- `ps_check_csrf` L110–113
- `ps_order` L115–140
- 加载：`require_once __DIR__ . '/../ProjectAccountRoles.php';`
- 加载：`require_once __DIR__ . '/../AuthStaff.php';`
- 加载：`require_once __DIR__ . '/../ProjectDeptHead.php';`

## includes/settlement/adjustment.php (183 行, 13.5 KB)

- `ps_next_open_month` L4–14
- `ps_post_adjustment` L21–107
- `ps_reclassify_order_kind` L110–180

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

## includes/settlement/labels_todos.php (75 行, 4.5 KB)

- `ps_label` L4–16
- `ps_order_todos` L22–42
- `ps_mask_contact` L48–56
- `ps_mask_id` L59–67
- `ps_contact_for` L70–74

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

## includes/settlement/rules.php (180 行, 11.9 KB)

- `ps_software_testing_enabled` L11–16
- `ps_rule_for` L19–54
- `ps_rules_for_person` L60–80
- `ps_role_rule_order_kind` L83–92
- `ps_rule` L94–97
- `money_plain` L99–102
- `ps_calc_person` L112–161
- `ps_trademark_piece_calc` L167–179
- 加载：`require_once __DIR__ . '/../ProjectCommissionRoles.php';`
- 加载：`require_once __DIR__ . '/../ProjectExtraRules.php';`

## includes/settlement/summary.php (320 行, 20 KB)

- `ps_summary` L3–230
- `ps_group_share_cents` L236–258
- `ps_allocate_pool_cents` L260–271
- `ps_group_subsidy_cents` L274–291
- `ps_settlement_preview` L293–300
- `ps_technical_reconciliation_summary` L302–312
- 加载：`require_once __DIR__ . '/../ProjectExtraRules.php';`

## includes/spot_check_banner.php (17 行, 1.2 KB)

- 加载：`require_once __DIR__ . '/ProjectSpotCheck.php';`

## includes/workbench-nav.php (26 行, 3.6 KB)

- 加载：`require_once __DIR__ . '/BizcnDomains.php';`

## index.php (164 行, 11.4 KB)

- 加载：`require_once __DIR__ . '/includes/auth.php';`
- 加载：`include __DIR__ . '/includes/header.php';`
- 加载：`require_once __DIR__ . '/includes/ProjectRenewals.php';`
- 加载：`include __DIR__ . '/includes/renewal_due_widget.php';`
- 加载：`include __DIR__ . '/includes/domain_missing_widget.php';`
- 加载：`include __DIR__ . '/includes/renewal_info_popup.php';`
- 加载：`include __DIR__ . '/includes/rule_algo_card.php';`
- 加载：`include __DIR__ . '/includes/footer.php';`

## insurance/index.php (167 行, 8.1 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPayrollExtras.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## jobs/bizcn_domain_status_sync.php (21 行, 1 KB)

- 加载：`require_once __DIR__ . '/../includes/functions.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnDomains.php';`

## jobs/etmll_sync_auto.php (18 行, 1.3 KB)

- 加载：`require_once __DIR__ . '/../includes/etmll_bidirectional.php';`

## jobs/governance_penalty_sync.php (45 行, 2.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectGovernance.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectWelfare.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## jobs/order_auto_finish_sync.php (16 行, 0.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## jobs/order_domains_sync.php (24 行, 1.4 KB)

- 加载：`require_once __DIR__ . '/../includes/functions.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnDomains.php';`

## jobs/project_auto_review.php (34 行, 1.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSpotCheck.php';`

## jobs/refund_auto_apply.php (13 行, 1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`

## jobs/renewal_sms.php (9 行, 0.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRenewalSms.php';`

## login.php (161 行, 9.7 KB)

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

## migrations/apply_overtime_pay.php (14 行, 0.8 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`
- 加载：`require_once __DIR__ . '/../includes/auth.php';`

## migrations/apply_password_recovery.php (20 行, 1.2 KB)

- 加载：`require_once __DIR__.'/../config/database.php';`

## migrations/apply_project.php (54 行, 2.7 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_refund_trash.php (9 行, 0.8 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/apply_renewal_contacts.php (8 行, 0.4 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectRenewals.php';`

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

## migrations/merge_split_trademark_cost_20261015.php (36 行, 2.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`

## migrations/provision_governance_accounts.php (37 行, 2.1 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`

## migrations/provision_graphic_designer.php (39 行, 2.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## migrations/provision_vault_accounts.php (51 行, 3.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## migrations/repair_trademark_pricing.php (43 行, 3.9 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectTrademarkCost.php';`

## migrations/set_recovery_phone.php (28 行, 2.7 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectVault.php';`

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

## orders/index/actions/upload/parse_rows/fields.php (189 行, 12.3 KB)

- 加载：`include $deptConfigFile;`
- 加载：`include $deptFeeFile) : [];`

## orders/index/actions/upload/parse_rows/part_1.php (3 行, 0 KB)


## orders/index/actions/upload/parse_rows/part_2.php (235 行, 15.2 KB)

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


## orders/index/actions/verify_status.php (71 行, 3.7 KB)


## orders/index/context.php (346 行, 16.7 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/auth.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/SalaryCalculator.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../classes/SimpleXLSX.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/index/helpers/applyOrderVerification.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/index/actions/dispatch.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/index/helpers/parseOrderDate.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/index/helpers/ensureProjectColumn.php';`

## orders/index/helpers/applyOrderVerification.php (149 行, 8 KB)

- `applyOrderVerification` L2–148

## orders/index/helpers/ensureProjectColumn.php (87 行, 5.3 KB)

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


## orders/index/view/js_2.php (318 行, 13.1 KB)


## orders/index/view/js_3.php (67 行, 2.9 KB)


## orders/index/view/section_1.php (289 行, 22.5 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_1.php';`

## orders/index/view/section_2.php (230 行, 17.6 KB)


## orders/index/view/section_3.php (253 行, 22.5 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_2.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_3.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/css/orders_index_4.css';`

## orders/index/view/section_4.php (31 行, 1.8 KB)

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

## project/batch_fill.php (94 行, 12.3 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectBatchFill.php';`
- 加载：`include __DIR__.'/../includes/header.php';`
- 加载：`include __DIR__.'/../includes/footer.php';`

## project/batch_review.php (106 行, 14.1 KB)

- `pbr_state_badge` L48–48
- 加载：`require_once __DIR__ . '/../includes/ProjectBatchReview.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/calc_modal.php (28 行, 1.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/commission_explain.php';`

## project/cdn.php (131 行, 15.2 KB)

- 加载：`require_once __DIR__ . '/../includes/BizcnDomains.php';`
- 加载：`require_once __DIR__ . '/../includes/QiniuCdn.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectApiSettings.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

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

## project/dns.php (325 行, 35.4 KB)

- 加载：`require_once __DIR__ . '/../includes/BizcnDomains.php';`
- 加载：`require_once __DIR__ . '/../includes/DnsProviders.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/domain_dns_choices.php (9 行, 1.3 KB)


## project/domain_registration_view.php (87 行, 11.7 KB)

- 加载：`include __DIR__.'/domain_dns_choices.php';`

## project/domains.php (286 行, 27.4 KB)

- 加载：`require_once __DIR__ . '/../includes/BizcnDomains.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnRegistration.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnDnsRouting.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__.'/domain_registration_view.php';`
- 加载：`include __DIR__.'/domain_dns_choices.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/dup_appeals.php (86 行, 9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectDupAppeal.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
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

## project/import/01_context.php (97 行, 6.9 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectIntake.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderSplit.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderJoin.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectSiteProjects.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectTrademarkCost.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectFilingCost.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectBusiness.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectAiFallback.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectDepartmentImport.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectImportResult.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectImportClassification.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectSheetEdit.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../classes/SimpleXLSX.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectMiniappTemplate.php';`

## project/import/02_read_sheets.php (169 行, 16.4 KB)


## project/import/03a_order_no_and_split.php (278 行, 29.4 KB)

- 加载：`require_once (dirname((dirname(__DIR__, 1)), 1)) . '/includes/ProjectDupAppeal.php';`

## project/import/03b_date_amount_status.php (313 行, 33.2 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectMiniappTemplate.php';`

## project/import/03c_people_resources.php (157 行, 16.8 KB)


## project/import/04_merge_items.php (33 行, 3.3 KB)


## project/import/04_merge_rows.php (115 行, 10.1 KB)

- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectOrderFix.php';`

## project/import/05_commit.php (4 行, 0.2 KB)

- 加载：`include __DIR__ . '/commit/part_1.php';`
- 加载：`include __DIR__ . '/commit/part_2.php';`

## project/import/06_followup.php (30 行, 2.4 KB)


## project/import/commit/part_1.php (64 行, 5.6 KB)


## project/import/commit/part_2.php (315 行, 33.6 KB)

- 加载：`require_once (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/dup_feedback.php';`
- 加载：`require_once (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/ProjectAutoReview.php';`
- 加载：`require_once (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/ProjectRenewalImport.php';`

## project/import/preview_controller.php (164 行, 15.5 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/import/02_read_sheets.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectRenewalImport.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/03a_order_no_and_split.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/03b_date_amount_status.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/03c_people_resources.php';`
- 加载：`require_once (dirname(__DIR__, 1)) . '/../includes/ProjectTrademarkTemplate.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/04_merge_items.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/04_merge_rows.php';`
- 加载：`include (dirname(__DIR__, 1)) . '/import/05_commit.php';`

## project/import/view.php (166 行, 25.8 KB)

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

## project/import/view/render_15.php (11 行, 4.4 KB)


## project/import/view/render_62.php (7 行, 1.3 KB)


## project/import/view/render_63.php (7 行, 1.4 KB)


## project/import/view/render_fix_panel.php (47 行, 4.2 KB)


## project/import/view/view/js_5.php (17 行, 1.7 KB)


## project/import_undo_api.php (26 行, 1.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectImportUndo.php';`

## project/index.php (113 行, 9.2 KB)

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
- 加载：`require_once __DIR__ . '/../includes/ProjectDeptHead.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderDelete.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderTrash.php';`
- 加载：`include __DIR__ . '/index/actions/quick_update.php';`
- 加载：`include __DIR__ . '/index/actions/delete.php';`
- 加载：`include __DIR__ . '/index/actions/bulk.php';`
- 加载：`include __DIR__ . '/index/actions/create.php';`
- 加载：`include __DIR__ . '/../includes/ProjectOrderList.php';`
- 加载：`include __DIR__ . '/index/view.php';`

## project/index/actions/bulk.php (89 行, 6 KB)

- 加载：`require_once (dirname(__DIR__, 3)) . '/includes/ProjectAutoReview.php';`

## project/index/actions/create.php (225 行, 20.9 KB)

- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectRenewalMath.php';`
- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectOrderJoin.php';`
- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectJointIntake.php';`
- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectTrademarkCost.php';`
- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectSheetEdit.php';`

## project/index/actions/delete.php (30 行, 1.7 KB)


## project/index/actions/quick_update.php (173 行, 8.9 KB)

- 加载：`require_once (dirname(__DIR__, 3)) . '/includes/ProjectDeptHead.php';`
- 加载：`require_once (dirname(__DIR__, 3)) . '/includes/ProjectSheetEdit.php';`
- 加载：`require_once (dirname(__DIR__, 3)) . '/includes/ProjectSheetEdit.php';`
- 加载：`require_once (dirname(__DIR__, 3)) . '/includes/ProjectAutoReview.php';`

## project/index/view.php (5 行, 0.5 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`
- 加载：`include __DIR__ . '/view/section_2.php';`
- 加载：`include __DIR__ . '/view/section_3.php';`

## project/index/view/js_1.php (15 行, 1 KB)


## project/index/view/js_2.php (25 行, 1.6 KB)


## project/index/view/js_4.php (140 行, 11.3 KB)


## project/index/view/js_5.php (42 行, 3.2 KB)


## project/index/view/quick_edit_modal.php (227 行, 10.6 KB)


## project/index/view/rule_link_modal.php (86 行, 6.4 KB)


## project/index/view/section_1.php (18 行, 3.2 KB)

- 加载：`require_once dirname(__DIR__,3).'/includes/ProjectRenewals.php';`

## project/index/view/section_2.php (152 行, 24.7 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/renewal_due_widget.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/domain_missing_widget.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/renewal_info_popup.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/finance_taobao_card.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/rule_algo_card.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/index/view/js_1.php';`

## project/index/view/section_3.php (165 行, 25.3 KB)

- 加载：`require_once __DIR__ . '/../../../includes/ProjectBatchReview.php';`
- 加载：`include __DIR__ . '/quick_edit_modal.php';`
- 加载：`include __DIR__ . '/rule_link_modal.php';`
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

## project/message_ack.php (36 行, 1.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectAnnouncements.php';`

## project/messages.php (30 行, 1.9 KB)

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


## project/order/actions/cost/add_cost.php (35 行, 3 KB)


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


## project/order/actions/dispatch.php (79 行, 6.9 KB)

- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/cost/link_item_cost.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/order_meta/save_customer_intake.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/order_meta/set_order_kind.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/participants/add_counterpart.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/participants/assign_backend.php';`
- 加载：`include (dirname(__DIR__, 2)) . '/order/actions/participants/add_software_tester.php';`
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


## project/order/actions/order_meta/set_order_kind.php (33 行, 1.7 KB)


## project/order/actions/order_meta/submit_commission_correction.php (23 行, 1.7 KB)


## project/order/actions/order_meta/update_order.php (13 行, 1.1 KB)


## project/order/actions/participants/add_counterpart.php (19 行, 1.7 KB)


## project/order/actions/participants/add_participant.php (30 行, 2.7 KB)


## project/order/actions/participants/add_software_tester.php (17 行, 1.8 KB)


## project/order/actions/participants/assign_backend.php (6 行, 0.3 KB)


## project/order/actions/participants/claim_backend.php (4 行, 0.1 KB)


## project/order/actions/participants/remove_participant.php (9 行, 0.5 KB)


## project/order/context.php (141 行, 8.1 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/order/actions/dispatch.php';`

## project/order/view.php (4 行, 0.4 KB)

- 加载：`include (dirname(__DIR__, 1)) . '/../includes/header.php';`
- 加载：`include __DIR__ . '/view/section_1.php';`
- 加载：`include __DIR__ . '/view/section_2.php';`
- 加载：`include __DIR__ . '/view/section_3.php';`
- 加载：`include __DIR__ . '/view/section_4.php';`

## project/order/view/section_1.php (199 行, 22.3 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/auto_review_card.php';`

## project/order/view/section_2.php (210 行, 20.1 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_1.js';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/order_credentials_card.php';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/order_renewal_card.php';`
- 加载：`require (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/project_order_items_view.php';`

## project/order/view/section_3.php (186 行, 22.9 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_2.js';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_3.js';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/project_order_4.js';`
- 加载：`include __DIR__ . '/software_testing.php';`

## project/order/view/section_4.php (6 行, 0.8 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php';`

## project/order/view/software_testing.php (58 行, 4.1 KB)


## project/order_fix_api.php (26 行, 1.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectOrderFix.php';`

## project/order_fixes.php (50 行, 5.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectOrderFix.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`require_once __DIR__ . '/../includes/correction_tabs.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/payroll.php (500 行, 61 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPayrollOrderSummary.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportReconciliation.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPayrollExtras.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/preview_as.php (59 行, 5.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/renewal_due_widget.php';`
- 加载：`include __DIR__ . '/../includes/rule_algo_card.php';`
- 加载：`include __DIR__ . '/../includes/kb_chat_examples_card.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/profile.php (88 行, 8.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/proof.php (43 行, 1.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

## project/refund_trash.php (39 行, 4.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/refunds.php (179 行, 30.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRefundMatch.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/refund_trash_action.php';`
- 加载：`include __DIR__ . '/../includes/refund_trash_action.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/renewal_fill.php (29 行, 2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRenewalFill.php';`

## project/renewal_gaps.php (37 行, 3.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRenewalFill.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/renewal_sms.php (33 行, 6.3 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectRenewalSms.php';`
- 加载：`include __DIR__.'/../includes/header.php';`
- 加载：`include __DIR__.'/../includes/footer.php';`

## project/renewals.php (98 行, 17 KB)

- `pr_url` L44–44
- 加载：`require_once __DIR__ . '/../includes/ProjectRenewalSms.php';`
- 加载：`include __DIR__.'/../includes/header.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectRenewalFill.php';`
- 加载：`include __DIR__.'/../includes/footer.php';`

## project/review.php (45 行, 8.4 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectAutoReview.php';`
- 加载：`include __DIR__.'/../includes/header.php';`
- 加载：`include __DIR__.'/../includes/footer.php';`

## project/rule_link_api.php (22 行, 1.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRuleLink.php';`

## project/rule_request_api.php (47 行, 2.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';`

## project/rule_requests.php (54 行, 6.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`require_once __DIR__ . '/../includes/correction_tabs.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/rules.php (441 行, 71.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`require __DIR__ . '/governance_rules.php';`
- 加载：`require __DIR__ . '/welfare_rules.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectReviewPolicy.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectExtraRules.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/joint_settlement_rules.php';`
- 加载：`include __DIR__ . '/../includes/review_policy_editor.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/service_performance.php (230 行, 20.9 KB)

- `csr_fmt` L84–84
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/lib/cs_perf_reception.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/settings.php (312 行, 49.9 KB)

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

## project/spot_checks.php (54 行, 5.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSpotCheck.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/system.php (197 行, 21.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectAiFallback.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/testing.php (48 行, 4.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/trademark_costs.php (48 行, 6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkDupCost.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`require_once __DIR__ . '/../includes/correction_tabs.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/trash.php (44 行, 3.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectOrderTrash.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## project/vault.php (276 行, 28.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectVault.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`
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

## recovery_phone.php (40 行, 3.9 KB)

- 加载：`require_once __DIR__.'/includes/RecoveryWeb.php';`

## recovery_sms.php (45 行, 4.8 KB)

- 加载：`require_once __DIR__.'/includes/RecoveryWeb.php';`

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

## shops/etmll_sync.php (240 行, 16.3 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/etmll_sync.php';`
- 加载：`require_once __DIR__ . '/../includes/etmll_push.php';`
- 加载：`require_once __DIR__ . '/../includes/etmll_bidirectional.php';`
- 加载：`include __DIR__ . '/../includes/header.php';`
- 加载：`include __DIR__ . '/../includes/footer.php';`

## shops/index.php (267 行, 11.7 KB)

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


## shops/upload/actions/upload.php (284 行, 21 KB)

- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ShopOrderDate.php';`
- 加载：`require_once (dirname(__DIR__, 2)) . '/../includes/ProjectShopState.php';`

## shops/upload/context.php (101 行, 4.7 KB)

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

## shops/upload/view/section_1.php (111 行, 7.8 KB)


## shops/upload/view/section_2.php (134 行, 10.8 KB)

- 加载：`include __DIR__ . '/section_2/content.php';`

## shops/upload/view/section_2/content.php (193 行, 17.6 KB)


## shops/upload/view/section_3.php (24 行, 0.9 KB)

- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../assets/js/shops_upload_1.js';`
- 加载：`include (dirname((dirname(__DIR__, 1)), 1)) . '/../includes/footer.php';`

## tests/_repro_eb.php (53 行, 4.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/agent_cloud_tools.php (78 行, 7 KB)

- `check_cloud` L4–4
- `rejected_cloud` L5–5
- `bda_clear_cache` L7–7
- `ps_audit` L8–8
- 加载：`require_once __DIR__.'/../includes/AgentCloudTools.php';`
- 加载：`require_once __DIR__.'/dns_fake_provider.php';`

## tests/attendance_approval_smoke.php (45 行, 3.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../project/rules.php';`
- 加载：`include __DIR__ . '/../project/rules.php';`

## tests/attendance_auto_review.php (35 行, 3.2 KB)

- `attendance_check` L5–8
- `attendance_result` L9–15
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/attendance_auto_rules_smoke.php (58 行, 4.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/../includes/AttendanceHomeWork.php';`

## tests/auth_staff.php (22 行, 1.9 KB)

- `staff_check` L4–4
- 加载：`require_once __DIR__.'/../includes/AuthStaff.php';`

## tests/batch_review_smoke.php (59 行, 5.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBatchReview.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderTrash.php';`

## tests/bid_flow_202608.php (67 行, 5.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/bizcn_dns_routing.php (52 行, 5.3 KB)

- `bd_require_owned` L5–5
- `bd_call` L6–6
- `bd_rows` L7–7
- `ps_audit` L8–8
- `check_route` L20–20
- `rejects_route` L21–21
- `writes_route` L22–22
- 加载：`require_once __DIR__.'/../includes/BizcnDnsRouting.php';`

## tests/bizcn_domain_catalog.php (27 行, 3 KB)

- `bc_check` L5–5
- `bc_reject` L6–6
- 加载：`require_once __DIR__.'/../includes/BizcnDomainValues.php';`
- 加载：`require_once __DIR__.'/../includes/BizcnDomainCatalog.php';`

## tests/bizcn_domain_order.php (26 行, 2.1 KB)

- `db` L6–6
- `bd_config` L7–7
- `order_check` L8–8
- 加载：`require_once __DIR__.'/../includes/BizcnDomainCatalog.php';`

## tests/bizcn_domain_status.php (76 行, 7.1 KB)

- `st` L4–4
- `bd_call` L6–14
- `trej` L48–48
- 加载：`require __DIR__ . '/../includes/BizcnDomainStatus.php';`

## tests/bizcn_domains.php (42 行, 3.8 KB)

- `bd_test` L4–4
- `bd_reject` L5–5
- 加载：`require_once __DIR__.'/../includes/BizcnDomainValues.php';`

## tests/bizcn_order_domains.php (85 行, 9.8 KB)

- `ot` L4–4
- `orej` L5–5
- `ps_audit` L7–7
- `bd_domain` L8–8
- `dns_public_ns` L9–9
- `bdr_provider` L10–10
- `db` L12–12
- 加载：`require __DIR__ . '/../includes/BizcnDomainStatus.php';`
- 加载：`require __DIR__ . '/../includes/BizcnOrderDomains.php';`

## tests/bizcn_record_replace.php (59 行, 5.9 KB)

- `replace_test` L4–4
- `replace_error` L5–5
- `replace_fixture` L6–25
- 加载：`require_once __DIR__.'/../includes/BizcnDnsRecords.php';`

## tests/bizcn_registration.php (63 行, 7.1 KB)

- `bt` L4–4
- `reject` L5–5
- `fixture_db` L6–8
- `fixture_schema` L9–13
- `fq` L14–14
- 加载：`require_once __DIR__.'/../includes/BizcnRegistrationStore.php';`

## tests/bizcn_registration_flow.php (109 行, 14.8 KB)

- `db` L6–6
- `bd_config` L7–7
- `bc_find` L8–8
- `ps_audit` L9–9
- `bd_call` L10–18
- `bd_transport` L19–19
- 加载：`require __DIR__.'/bizcn_registration.php';`
- 加载：`require __DIR__.'/../includes/BizcnDomainValues.php';`
- 加载：`require __DIR__.'/../includes/BizcnRegistration.php';`

## tests/bizcn_template_ai.php (126 行, 12.2 KB)

- `tt` L5–5
- `trej` L6–6
- `ps_audit` L8–8
- `ps_ai_ready` L9–9
- `ps_ai_chat` L10–10
- `ps_ai_json` L11–11
- `bd_call` L12–12
- `ps_setting_get` L14–14
- `ps_setting_set` L15–15
- `noisy` L75–75
- 加载：`require __DIR__ . '/../includes/BizcnTemplateAi.php';`

## tests/channel_receipt_match_smoke.php (42 行, 3.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectAutoReview.php';`

## tests/custom_site_cs_smoke.php (44 行, 3.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/dept_head_view_smoke.php (32 行, 2.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectDeptHead.php';`

## tests/dept_orders_compare_202608.php (140 行, 9.5 KB)

- 加载：`require __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/designer_split_import_smoke.php (86 行, 8.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/dns_fake_provider.php (55 行, 4.8 KB)

- `dns_fake_state` L3–3
- `dns_fake_install` L4–54

## tests/dns_management.php (135 行, 13.2 KB)

- 加载：`require_once __DIR__ . '/../includes/DnsProviders.php';`
- 加载：`require_once __DIR__ . '/dns_fake_provider.php';`

## tests/dns_page_smoke.php (153 行, 13.4 KB)

- 加载：`require_once __DIR__ . '/../includes/BizcnDomains.php';`
- 加载：`require_once __DIR__ . '/../includes/DnsProviders.php';`
- 加载：`require_once __DIR__ . '/dns_fake_provider.php';`
- 加载：`include __DIR__ . '/../project/dns.php';`
- 加载：`include __DIR__ . '/../project/dns.php';`

## tests/dup_appeal_smoke.php (111 行, 11.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectDupAppeal.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderTrash.php';`

## tests/etmll_push_latest_smoke.php (41 行, 5.4 KB)

- `latest_check` L4–4
- 加载：`require_once __DIR__.'/../includes/etmll_push.php';`
- 加载：`include existing old-created upload, refund status, and one new order');`

## tests/etmll_sync_smoke.php (131 行, 11.9 KB)

- `etmll_check` L6–9
- 加载：`require_once __DIR__ . '/../includes/etmll_sync.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectShopState.php';`
- 加载：`include missing shop flows and source updates');`
- 加载：`include verified/new creation and exclude deleted/old orders');`

## tests/extra_rule_smoke.php (55 行, 5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectExtraRules.php';`

## tests/filing_template_xlsx.php (28 行, 1.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMiniappTemplate.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`

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

## tests/import_filing_smoke.php (141 行, 14.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMiniappTemplate.php';`

## tests/import_fix_panel_smoke.php (37 行, 4.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/import_followup_smoke.php (69 行, 6.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../includes/import_followup_modal.php';`
- 加载：`include __DIR__ . '/../includes/import_followup_modal.php';`

## tests/import_join_smoke.php (145 行, 13.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/import_lenient_people_smoke.php (62 行, 5.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/import_miniapp_kind_smoke.php (102 行, 10.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/import_overwrite_smoke.php (110 行, 12 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/import_replay_fixes_smoke.php (83 行, 8.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/import_status_forward_smoke.php (50 行, 5.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/import_web_template_smoke.php (88 行, 8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMiniappTemplate.php';`

## tests/inspect_domains.php (10 行, 0.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/knowledge_categories_unit.php (25 行, 1.8 KB)

- `kbc_check` L5–5
- `kbc_reject` L6–6
- 加载：`require_once __DIR__ . '/../includes/ProjectKnowledge.php';`

## tests/order_delete_smoke.php (69 行, 7.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderDelete.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderTrash.php';`

## tests/order_import_integrity.php (67 行, 6.8 KB)

- `oi_check` L10–10
- `oi_reject` L11–11
- 加载：`require_once __DIR__ . '/../includes/ProjectRenewalImportFields.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSiteProjects.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPayrollOrderSummary.php';`
- 加载：`require_once __DIR__ . '/../includes/intake/participants_orders.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportParse.php';`
- 加载：`require_once __DIR__ . '/../includes/intake/parse_rows.php';`

## tests/order_no_canonical_smoke.php (26 行, 1.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/order_no_clean_smoke.php (10 行, 0.7 KB)

- 加载：`require_once __DIR__ . '/../includes/lib/order_text.php';`

## tests/overtime_pay_smoke.php (100 行, 7.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tests/payroll_extras_smoke.php (49 行, 3.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPayrollExtras.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`

## tests/payroll_trademark_pieces_smoke.php (36 行, 3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPayrollOrderSummary.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`

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

## tests/project_auto_review_unit.php (106 行, 15.1 KB)

- `ar_check` L5–5
- `ar_base` L6–12
- `ar_state` L13–13
- 加载：`require_once __DIR__.'/../includes/ProjectAutoReviewMath.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectBusiness.php';`

## tests/project_batch_fill_storage.php (83 行, 8.6 KB)

- `check` L19–19
- `reject` L20–20
- 加载：`require_once __DIR__.'/../includes/ProjectBatchFill.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectSheetEdit.php';`

## tests/project_batch_fill_unit.php (79 行, 6.7 KB)

- `check` L4–4
- `reject` L5–5
- 加载：`require_once __DIR__.'/../includes/ProjectBatchFill.php';`

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

## tests/project_finance_assignment_smoke.php (49 行, 4 KB)

- `finance_check` L5–8
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`

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

## tests/project_management_import_smoke.php (58 行, 5.4 KB)

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

## tests/project_renewals_unit.php (64 行, 5.6 KB)

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

## tests/project_terms_smoke.php (33 行, 3.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectTerms.php';`

## tests/project_website_rewards.php (119 行, 9.7 KB)

- `wr_check` L11–15
- `wr_insert` L16–19
- `wr_ranking` L79–85
- 加载：`require_once $wr_app_root . '/includes/ProjectWebsiteRewards.php';`
- 加载：`require_once $wr_app_root . '/includes/ProjectMonthly.php';`
- 加载：`require_once $wr_app_root . '/includes/ProjectPresets.php';`
- 加载：`include $wr_app_root.'/includes/monthly/types/ranking_manual.php';`

## tests/project_welfare_smoke.php (78 行, 6.7 KB)

- `welfare_check` L3–3
- 加载：`require_once __DIR__ . '/../includes/ProjectWelfare.php';`
- 加载：`include __DIR__ . '/../project/welfare.php';`
- 加载：`include __DIR__ . '/../project/governance_election.php';`
- 加载：`include __DIR__ . '/../project/welfare.php';`

## tests/qiniu_cdn.php (74 行, 7.2 KB)

- `qc_test` L4–4
- `qc_reject` L5–5
- 加载：`require_once __DIR__.'/../includes/QiniuCdn.php';`

## tests/quick_update_smoke.php (109 行, 4.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSheetEdit.php';`
- 加载：`include __DIR__ . '/../project/index/actions/quick_update.php';`
- 加载：`include __DIR__ . '/../project/index/actions/bulk.php';`
- 加载：`include __DIR__ . '/../project/index/actions/bulk.php';`

## tests/refund_auto_close_smoke.php (45 行, 4.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRefundImport.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRefundMatch.php';`

## tests/renewal_fill_smoke.php (74 行, 6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRenewalFill.php';`

## tests/require_isolated_database.php (11 行, 0.5 KB)

- `require_isolated_test_database` L3–10

## tests/rule_link_smoke.php (81 行, 6.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRuleLink.php';`

## tests/salary_monthly_module_entry.php (24 行, 1.4 KB)

- `get_attendance` L4–8
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`

## tests/service_performance_smoke.php (113 行, 10.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/lib/cs_perf_reception.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMiniappTemplate.php';`

## tests/sheet_editor_smoke.php (51 行, 3.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectSheetEdit.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`

## tests/shop_order_date_unit.php (35 行, 1.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ShopOrderDate.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectImportParse.php';`

## tests/shop_statement_sync_smoke.php (42 行, 4.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`

## tests/shop_upload_dates_smoke.php (65 行, 4.8 KB)

- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`include __DIR__ . '/../shops/upload.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/software_tester_join_smoke.php (55 行, 5.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`include __DIR__ . '/../project/order/actions/participants/' . $file;`

## tests/software_testing_smoke.php (58 行, 5.9 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRuleAlgo.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/split_source.php (12 行, 0.5 KB)

- `split_test_source` L4–11
- 加载：`require_once __DIR__ . '/../tools/code_structure.php';`

## tests/spot_check_smoke.php (54 行, 4.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSpotCheck.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectRefundMatch.php';`
- 加载：`include __DIR__ . '/../includes/spot_check_banner.php';`

## tests/test_domain_dispatch.php (31 行, 1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnDomains.php';`
- 加载：`require_once __DIR__ . '/../includes/DnsProviders.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnDnsRouting.php';`

## tests/test_dongxu_view.php (34 行, 1.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`

## tests/test_extract_domains.php (51 行, 2.1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tests/test_laibangwo_dns.php (33 行, 1 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnDomains.php';`
- 加载：`require_once __DIR__ . '/../includes/DnsProviders.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnDnsRouting.php';`

## tests/test_password_recovery.php (164 行, 15.4 KB)

- `db` L5–5
- `pv_encrypt` L6–10
- `pv_decrypt` L11–14
- `check` L16–16
- `rejects` L17–20
- `fixture` L21–40
- `bindAdmin` L41–45
- 加载：`require_once __DIR__.'/../includes/PasswordRecovery.php';`
- 加载：`require_once __DIR__.'/../includes/RecoverySms.php';`

## tests/test_trademark_integration.php (134 行, 7.9 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`
- 加载：`require_once __DIR__ . '/../includes/auth.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectPresets.php';`
- 加载：`require_once __DIR__ . '/../includes/SalaryCalculator.php';`
- 加载：`require_once __DIR__ . '/../classes/SimpleXLSX.php';`

## tests/test_unified_domains.php (54 行, 2.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnDomains.php';`
- 加载：`require_once __DIR__ . '/../includes/DnsProviders.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnDnsRouting.php';`
- 加载：`require_once __DIR__ . '/../includes/BizcnDomainAggregator.php';`

## tests/trademark_cost_flow_smoke.php (114 行, 11.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/trademark_cost_sync_smoke.php (85 行, 7.7 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`

## tests/trademark_department_smoke.php (127 行, 6.3 KB)

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

## tests/trademark_pricing_import_smoke.php (103 行, 14.2 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectTrademarkCost.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__.'/require_isolated_database.php';`
- 加载：`require __DIR__.'/../migrations/apply_management_accounts.php';`
- 加载：`include __DIR__.'/../project/import.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectTrademarkTemplate.php';`

## tests/trademark_pricing_smoke.php (122 行, 12.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectCostDisplay.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`include __DIR__.'/../project/order/actions/cost/review_cost.php';`
- 加载：`include __DIR__.'/../project/order/actions/cost/review_cost.php';`

## tests/trademark_split_order_cost_smoke.php (110 行, 9.6 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkCost.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectOrderSource.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectCostDisplay.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectTrademarkDupCost.php';`

## tests/trademark_template_smoke.php (37 行, 3.5 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__.'/../includes/ProjectMiniappTemplate.php';`
- 加载：`require_once __DIR__.'/require_isolated_database.php';`

## tests/trademark_upload_flow_202608.php (81 行, 5.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectDepartmentImport.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/vault_item_access.php (88 行, 6.8 KB)

- `va_check` L10–14
- `va_denied` L15–18
- 加载：`require_once $testRoot . '/includes/ProjectVault.php';`
- 加载：`require_once $testRoot . '/tests/require_isolated_database.php';`

## tests/vault_smoke.php (72 行, 7.2 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectVault.php';`
- 加载：`include __DIR__ . '/../project/vault.php';`
- 加载：`include __DIR__ . '/../includes/order_credentials_card.php';`
- 加载：`include __DIR__ . '/../includes/order_credentials_card.php';`

## tests/website_delivery_and_upgrade_smoke.php (186 行, 12 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectBusiness.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSettlement.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSystem.php';`

## tests/website_split_import_smoke.php (122 行, 11.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`include __DIR__ . '/../project/import.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSheetEdit.php';`

## tests/wechat_writing_smoke.php (82 行, 6.5 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tests/wechat_writing_sources.php (112 行, 12.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`
- 加载：`require_once __DIR__ . '/require_isolated_database.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectMiniappTemplate.php';`
- 加载：`include __DIR__.'/../project/import.php';`
- 加载：`require_once __DIR__.'/../classes/SimpleXLSX.php';`

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

## tools/configure_lixue_software_testing.php (45 行, 4.3 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectMonthly.php';`

## tools/dept_orders_switch_new_algo_202608.php (116 行, 6.8 KB)

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

## tools/import_status_catchup.php (71 行, 4.7 KB)

- `ir_preview` L13–30
- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`
- 加载：`require_once __DIR__ . '/../includes/ProjectSystem.php';`
- 加载：`include __DIR__ . '/../project/import.php';`

## tools/login_unlock.php (29 行, 1.3 KB)

- 加载：`require_once __DIR__ . '/../config/database.php';`
- 加载：`require_once __DIR__ . '/../includes/AuthThrottle.php';`

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


## tools/repair_import_contacts.php (105 行, 8.9 KB)

- 加载：`require_once $appRoot . '/includes/ProjectIntake.php';`
- 加载：`require_once $appRoot . '/includes/ProjectRenewalImport.php';`
- 加载：`require_once $appRoot . '/includes/ProjectSiteProjects.php';`
- 加载：`require_once $appRoot . '/includes/ProjectOrderSource.php';`

## tools/repair_joint_customer_snapshots.php (59 行, 5.9 KB)

- 加载：`require_once __DIR__.'/../includes/ProjectSettlement.php';`

## tools/repair_order_import_duplicates.php (54 行, 8.7 KB)

- 加载：`require_once $appRoot . '/includes/ProjectIntake.php';`
- 加载：`require_once $appRoot . '/includes/ProjectOrderSource.php';`
- 加载：`require_once $appRoot . '/includes/ProjectSiteProjects.php';`

## tools/repair_shared_payment_site_orders_20261008.php (150 行, 13.8 KB)

- `site3_cents` L29–29
- `site3_assert` L30–30
- `site3_rows` L31–33
- `site3_source` L34–38
- 加载：`require_once '/www/wwwroot/hezuoshang/includes/ProjectIntake.php';`
- 加载：`require_once '/www/wwwroot/hezuoshang/includes/ProjectOrderSplit.php';`
- 加载：`require_once '/www/wwwroot/hezuoshang/includes/ProjectSiteProjects.php';`
- 加载：`require_once '/www/wwwroot/hezuoshang/includes/ProjectAutoReview.php';`

## tools/repair_shop_order_dates.php (54 行, 3.8 KB)

- 加载：`require_once $root . '/includes/ProjectIntake.php';`
- 加载：`require_once $root . '/includes/ShopOrderDate.php';`

## tools/repair_trademark_costs.php (5 行, 0.2 KB)

- 加载：`require __DIR__ . '/../migrations/repair_trademark_pricing.php';`

## tools/repair_website_order_items.php (67 行, 4.4 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tools/seed_filing_costs.php (26 行, 1.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tools/seed_trademark_costs.php (47 行, 2.8 KB)

- 加载：`require_once __DIR__ . '/../includes/ProjectIntake.php';`

## tools/split_runtime.php (26 行, 1.2 KB)

- 加载：`require_once $root . '/includes/' . $entry;`

## tools/sync_shop_statements.php (28 行, 1.8 KB)

- 加载：`require_once $root . '/includes/ProjectIntake.php';`
- 加载：`require_once $root . '/includes/ProjectOrderSource.php';`

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

