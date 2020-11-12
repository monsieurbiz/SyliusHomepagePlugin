# Upgrade from dev to 1.X.X

In the 1.x we changed the table name of entities.
If you have a running instance of the plugin you can run this SQL to rename the tables : 

```sql
RENAME TABLE `mbiz_homepage_homepage` TO `monsieurbiz_homepage_homepage`;
RENAME TABLE `mbiz_homepage_homepage_channels` TO `monsieurbiz_cms_page_translation`;
RENAME TABLE `mbiz_homepage_homepage_translation` TO `monsieurbiz_homepage_homepage_translation`;
```
     
We upgraded also the [Rich Editor to the 2.0 version]((https://github.com/monsieurbiz/SyliusRichEditorPlugin/blob/master/UPGRADE-2.0.md)).  
