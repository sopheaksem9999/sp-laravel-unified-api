import { defineConfig } from 'vitepress'

export default defineConfig({
  title: "SP Laravel API",
  description: "Unified Dynamic CRUD API for Laravel",
  ignoreDeadLinks: true,
  themeConfig: {
    nav: [
      { text: 'Home', link: '/' },
      { text: 'Guide', link: '/getting-started/mental-model' }
    ],
    sidebar: [
      {
        text: 'Getting Started',
        items: [
          { text: 'The Mental Model', link: '/getting-started/mental-model' },
          { text: 'Architecture Overview', link: '/getting-started/architecture' },
        ]
      },
      {
        text: 'Core Concepts',
        items: [
          { text: 'Record Table Types', link: '/core-concepts/record-table-types' },
          { text: 'Relationships', link: '/core-concepts/relationships' },
          { text: 'API Documentation', link: '/core-concepts/api-documentation' },
        ]
      },
      {
        text: 'Features',
        items: [
          { text: 'Audit Logging', link: '/features/audit-logging' },
          { text: 'Attachments', link: '/features/attachments' },
          { text: 'Webhooks', link: '/features/webhooks' },
        ]
      },
      {
        text: 'Advanced',
        items: [
          { text: 'Troubleshooting', link: '/advanced/troubleshooting' },
        ]
      }
    ],
    search: {
      provider: 'local'
    }
  }
})