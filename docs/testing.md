# Testing

Run the test suite (requires server running):

```bash
# Start server in one terminal
npm start

# Run tests in another terminal
npm test
```

Individual tests:

```bash
npm run test:signatures    # Thinking signatures
npm run test:multiturn     # Multi-turn with tools
npm run test:streaming     # Streaming SSE events
npm run test:interleaved   # Interleaved thinking
npm run test:images        # Image processing
npm run test:caching       # Prompt caching
npm run test:strategies    # Account selection strategies
npm run test:cache-control # Cache control field stripping
```
