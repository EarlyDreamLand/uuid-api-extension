# UUID 扩展

本插件需搭配 blessing-skin-server 以及其插件 yggdrasil-connect 使用

## 功能

新增了 3 个 API接口

1. UUID/NAME 获取玩家头像 `/api/avatar/{uuid} or {name}`
2. 获取玩家全身渲染图 `/api/full/{uuid} or {name}` (使用 VZGE 渲染图源)
3. 获取玩家半身渲染图 `/api/bust/{uuid} or {name}` (使用 VZGE 渲染图源)