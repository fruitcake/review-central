import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'Review Central',
  description: 'Self-hosted AI code review for private Bitbucket repositories, and GitHub: faster and cheaper than an AI step in Bitbucket Pipelines, with the ticket as context. Open source.',
  lang: 'en-GB',
  cleanUrls: true,
  lastUpdated: true,
  appearance: false,
  head: [
    ['link', { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' }],
    ['meta', { name: 'theme-color', content: '#0f766e' }],
    ['meta', { property: 'og:title', content: 'Review Central' }],
    ['meta', { property: 'og:description', content: 'Self-hosted AI code review for private Bitbucket repositories, and GitHub. Open source.' }],
    ['meta', { property: 'og:image', content: 'https://review-central.com/screenshots/review.png' }],
  ],
  themeConfig: {
    logo: '/logo.svg',
    nav: [
      { text: 'Guide', link: '/guide/what-is-review-central' },
      { text: 'Quick start', link: '/guide/quick-start' },
      { text: 'GitHub', link: 'https://github.com/fruitcake/review-central' },
    ],
    sidebar: {
      '/guide/': [
        {
          text: 'Introduction',
          items: [
            { text: 'What is Review Central?', link: '/guide/what-is-review-central' },
            { text: 'Quick start', link: '/guide/quick-start' },
          ],
        },
        {
          text: 'Running it',
          items: [
            { text: 'Installation', link: '/guide/installation' },
            { text: 'Where a review runs', link: '/guide/runners' },
            { text: 'Updating', link: '/guide/updating' },
            { text: 'Configuration', link: '/guide/configuration' },
            { text: 'Live updates', link: '/guide/live-updates' },
          ],
        },
        {
          text: 'Using it',
          items: [
            { text: 'Setting up a tenant', link: '/guide/tenants' },
            { text: 'Reviews, settings and cost', link: '/guide/reviews' },
            { text: 'Appearance', link: '/guide/appearance' },
          ],
        },
        {
          text: 'More',
          items: [
            { text: 'Extending', link: '/guide/extending' },
            { text: 'Security', link: '/guide/security' },
            { text: 'Contributing', link: '/guide/contributing' },
          ],
        },
      ],
    },
    socialLinks: [{ icon: 'github', link: 'https://github.com/fruitcake/review-central' }],
    editLink: {
      pattern: 'https://github.com/fruitcake/review-central/edit/main/docs/:path',
      text: 'Edit this page on GitHub',
    },
    search: { provider: 'local' },
    footer: {
      message: 'Open source under the MIT License.',
      copyright: 'Developed by <a href="https://fruitcake.nl">Fruitcake</a>',
    },
  },
})
